<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Services\ManagerHierarchyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class MeController extends Controller
{
    /**
     * Returns the authenticated user's own linked employee record, if
     * any. No route parameter — the "object" is always the caller's own
     * link, resolved server-side, so there is no possible way to request
     * another employee's record through this endpoint. Platform admins
     * have no employee concept and get the same safe 404 as an
     * unlinked tenant user — never tenant data.
     */
    public function employee(Request $request): EmployeeResource
    {
        $employee = $request->user()->employee;

        abort_if($employee === null, 404, 'No linked employee record.');

        return new EmployeeResource($employee);
    }

    /**
     * Self-service, no permission required — deliberately, since this is
     * scoped only to the caller's own linked employee's direct reports,
     * never anyone else's. A caller with no linked employee gets an
     * empty list (200), not a 404 — same "safe empty response for a
     * list endpoint" posture already used by
     * LeaveRequestController::index() in Checkpoint 12, since a list
     * naturally has "nothing to show" as its safe unlinked state,
     * unlike the single-resource /me/employee (404). See
     * docs/security.md.
     */
    public function directReports(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        if ($employee === null) {
            return EmployeeResource::collection(new LengthAwarePaginator([], 0, 15));
        }

        $reports = app(ManagerHierarchyService::class)->directReportsOf($employee);

        return EmployeeResource::collection($reports);
    }
}
