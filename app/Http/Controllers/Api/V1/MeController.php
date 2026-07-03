<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use Illuminate\Http\Request;

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
}
