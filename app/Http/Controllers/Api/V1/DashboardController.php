<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AcknowledgementStatus;
use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\User;
use App\Services\LeaveVisibilityService;
use App\Services\ManagerHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Dashboard summary (Checkpoint 21) — deliberately not a listing
 * endpoint: every value here is an aggregate (a count, a sum, a handful
 * of safe labels) computed server-side, never a raw record dump. Each
 * card is independently gated by the same module permission its real
 * page/endpoint already requires — `dashboard.view` (checked by the
 * route's `permission:` middleware) only grants reaching this endpoint
 * at all, never any module's data on its own. See docs/security.md.
 */
class DashboardController extends Controller
{
    private const DOCUMENT_EXPIRING_WITHIN_DAYS = 30;

    private const DOCUMENT_RECENT_WITHIN_DAYS = 7;

    private const RECENT_ITEMS_LIMIT = 3;

    /**
     * `permission:dashboard.view` middleware already blocks Platform
     * Super Admins (a platform role can never hold a tenant-scoped
     * permission) — this explicit check is defense in depth, and matters
     * concretely here: BelongsToTenant's global scope only filters
     * queries when a Tenant is bound in the container; a platform admin
     * reaching this method with no tenant bound would otherwise make
     * every count below silently cross-tenant. See
     * app/Models/Concerns/BelongsToTenant.php.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user->is_platform_admin, 403, 'Dashboard summary is tenant-scoped only.');

        $employee = $user->employee;

        $cards = [];
        $recentItems = [];
        $quickLinks = [];

        if ($user->hasPermission('employees.view')) {
            $cards[] = $this->card('total_employees', 'Total Employees', Employee::query()->count(), '/employees', 'employees.view');
            $cards[] = $this->card('active_employees', 'Active Employees', Employee::query()->where('status', 'active')->count(), '/employees', 'employees.view');

            foreach (Employee::query()->orderByDesc('created_at')->limit(self::RECENT_ITEMS_LIMIT)->get() as $recentEmployee) {
                $recentItems[] = ['type' => 'employee', 'label' => $recentEmployee->fullName(), 'href' => "/employees/{$recentEmployee->id}"];
            }

            $quickLinks[] = ['label' => 'View employees', 'href' => '/employees'];
        }

        if ($user->hasPermission('employees.view_team') && $employee !== null) {
            $reportCount = app(ManagerHierarchyService::class)->directReportsOf($employee)->count();
            $cards[] = $this->card('direct_reports', 'My Direct Reports', $reportCount, null, 'employees.view_team');
        }

        if ($user->hasPermission('leave.view')) {
            [$pendingCount, $label] = $this->pendingLeaveSummary($user);
            $cards[] = $this->card('pending_leave', $label, $pendingCount, '/leave', 'leave.view');

            foreach ($this->recentLeaveRequests($user) as $leaveRequest) {
                $recentItems[] = [
                    'type' => 'leave',
                    'label' => "Leave request — {$leaveRequest->status->value}",
                    'href' => "/leave/{$leaveRequest->id}",
                ];
            }

            $quickLinks[] = ['label' => 'View leave', 'href' => '/leave'];

            if ($employee !== null) {
                $availableDays = LeaveBalance::query()
                    ->where('employee_id', $employee->id)
                    ->where('year', now()->year)
                    ->get()
                    ->sum(fn (LeaveBalance $balance) => $balance->availableDays());

                $cards[] = $this->card('my_leave_balance', 'My Leave Balance (Days Available)', round($availableDays, 1), '/leave', 'leave.view');
            }
        }

        if ($user->hasPermission('leave.request')) {
            $quickLinks[] = ['label' => 'Request leave', 'href' => '/leave/create'];
        }

        // Documents: deliberately self-scoped only, never tenant-wide —
        // there is no documents.view_all-equivalent permission yet to
        // safely gate a tenant-wide count behind. See docs/security.md.
        if ($user->hasPermission('documents.view') && $employee !== null) {
            $documentsQuery = EmployeeDocument::query()->where('employee_id', $employee->id);

            if (! $user->hasPermission('documents.view_sensitive')) {
                $documentsQuery->where('is_sensitive', false);
            }

            $expiringCount = (clone $documentsQuery)
                ->whereNotNull('expiry_date')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(self::DOCUMENT_EXPIRING_WITHIN_DAYS)->toDateString()])
                ->count();

            $recentUploadsCount = (clone $documentsQuery)
                ->where('created_at', '>=', now()->subDays(self::DOCUMENT_RECENT_WITHIN_DAYS))
                ->count();

            $documentsHref = "/employees/{$employee->id}/documents";
            $cards[] = $this->card('my_documents_expiring_soon', 'My Documents Expiring Soon', $expiringCount, $documentsHref, 'documents.view');
            $cards[] = $this->card('my_documents_recent', 'My Recently Uploaded Documents', $recentUploadsCount, $documentsHref, 'documents.view');

            $quickLinks[] = ['label' => 'View my documents', 'href' => $documentsHref];
        }

        if ($user->hasPermission('policies.view')) {
            $cards[] = $this->card('policies_total', 'Policies', Policy::query()->count(), '/policies', 'policies.view');
            $quickLinks[] = ['label' => 'View policies', 'href' => '/policies'];
        }

        if ($user->hasPermission('policies.view_acknowledgements')) {
            $pendingAckCount = PolicyAcknowledgement::query()
                ->where('acknowledgement_status', AcknowledgementStatus::Pending)
                ->count();

            $cards[] = $this->card('policies_pending_acknowledgement', 'Policies Pending Acknowledgement', $pendingAckCount, '/policies', 'policies.view_acknowledgements');
        }

        if ($user->hasPermission('policies.acknowledge') && $employee !== null) {
            $myPendingAckCount = PolicyAcknowledgement::query()
                ->where('employee_id', $employee->id)
                ->where('acknowledgement_status', AcknowledgementStatus::Pending)
                ->count();

            $cards[] = $this->card('my_policies_pending_acknowledgement', 'My Policies Pending Acknowledgement', $myPendingAckCount, '/policies', 'policies.acknowledge');
        }

        return response()->json([
            'cards' => $cards,
            'quick_links' => $quickLinks,
            'recent_items' => $recentItems,
        ]);
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function pendingLeaveSummary(User $user): array
    {
        if ($user->hasPermission('leave.view_all')) {
            $count = LeaveRequest::query()->where('status', LeaveRequestStatus::Pending)->count();

            return [$count, 'Pending Leave Requests'];
        }

        $employeeIds = app(LeaveVisibilityService::class)->visibleEmployeeIds($user);

        if ($employeeIds === []) {
            return [0, 'My Pending Leave Requests'];
        }

        $count = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveRequestStatus::Pending)
            ->count();

        $label = $user->hasPermission('leave.view_team') ? 'Pending Leave Requests (My Team)' : 'My Pending Leave Requests';

        return [$count, $label];
    }

    /**
     * @return Collection<int, LeaveRequest>
     */
    protected function recentLeaveRequests(User $user)
    {
        if ($user->hasPermission('leave.view_all')) {
            return LeaveRequest::query()->orderByDesc('created_at')->limit(self::RECENT_ITEMS_LIMIT)->get();
        }

        $employeeIds = app(LeaveVisibilityService::class)->visibleEmployeeIds($user);

        if ($employeeIds === []) {
            return collect();
        }

        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_ITEMS_LIMIT)
            ->get();
    }

    /**
     * @return array{key: string, label: string, value: int|float, href: string|null, permission: string}
     */
    protected function card(string $key, string $label, int|float $value, ?string $href, string $permission): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'href' => $href,
            'permission' => $permission,
        ];
    }
}
