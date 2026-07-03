<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\ManagerHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeHierarchyController extends Controller
{
    /**
     * How many levels deep GET .../reporting-tree will recurse before
     * stopping and reporting the remainder as truncated, rather than
     * fetching without limit. Unlike ManagerHierarchyService's
     * MAX_CHAIN_WALK (a corruption/cycle safety net), this is purely a
     * response-size/performance cap for a *display* endpoint — a real
     * org can legitimately be deeper than this; a future org-chart UI
     * would page or lazy-load beyond it. See docs/api.md.
     */
    private const DEFAULT_REPORTING_TREE_DEPTH = 5;

    public function directReports(Request $request, Employee $employee): AnonymousResourceCollection
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $reports = app(ManagerHierarchyService::class)->directReportsOf($employee);

        return EmployeeResource::collection($reports);
    }

    public function reportingTree(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $tree = $this->buildTreeNode($employee, $request, depth: 0);

        return response()->json(['data' => $tree]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTreeNode(Employee $employee, Request $request, int $depth): array
    {
        $node = (new EmployeeResource($employee))->toArray($request);

        $directReports = app(ManagerHierarchyService::class)->directReportsOf($employee);

        if ($directReports->isEmpty()) {
            $node['direct_reports'] = [];
            $node['reports_truncated'] = false;

            return $node;
        }

        if ($depth >= self::DEFAULT_REPORTING_TREE_DEPTH) {
            $node['direct_reports'] = [];
            $node['reports_truncated'] = true;

            return $node;
        }

        $node['direct_reports'] = $directReports
            ->map(fn (Employee $report) => $this->buildTreeNode($report, $request, $depth + 1))
            ->all();
        $node['reports_truncated'] = false;

        return $node;
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    protected function ensureBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }
}
