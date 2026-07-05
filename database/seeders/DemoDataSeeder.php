<?php

namespace Database\Seeders;

use App\Enums\AcknowledgementStatus;
use App\Enums\DocumentAppliesTo;
use App\Enums\DocumentCategoryStatus;
use App\Enums\DocumentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Enums\PolicyStatus;
use App\Models\Department;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyVersion;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Checkpoint 26: realistic, non-excessive demo data for the UESL tenant
 * only — Air Peace / Ibom Air (the other two seeded demo tenants) are
 * left exactly as UserSeeder/TenantSeeder already set them up, so this
 * checkpoint doesn't multiply tenants.
 *
 * Every row here is plain Eloquent creation, not a route call, so
 * nothing in this seeder writes an audit log itself. The seeded audit
 * trail comes from UserSeeder's real assignRole() calls (role.assigned
 * entries), and further entries accrue naturally once a real login
 * exercises the app during the live smoke test — see docs/testing.md.
 * No fabricated audit incidents are ever written here.
 *
 * Idempotent throughout (firstOrCreate / updateOrCreate keyed on a
 * natural unique column) so a bare `db:seed` re-run against an
 * already-seeded database never duplicates rows. migrate:fresh drops
 * every table first, so idempotency only matters for that second case.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->where('subdomain', 'uesl')->first();

        if ($tenant === null) {
            return;
        }

        $departments = $this->seedDepartments($tenant);
        $positions = $this->seedPositions($tenant);
        $locations = $this->seedLocations($tenant);

        $employees = $this->seedEmployees($tenant, $departments, $positions, $locations);

        $leaveTypes = $this->seedLeaveTypes($tenant);
        $this->seedLeaveBalancesAndRequests($tenant, $employees, $leaveTypes);

        $categories = $this->seedDocumentCategories($tenant);
        $this->seedDocuments($tenant, $employees, $categories);

        $this->seedPolicies($tenant, $employees);
    }

    /**
     * @return array<string, Department>
     */
    private function seedDepartments(Tenant $tenant): array
    {
        $names = ['Engineering', 'Human Resources', 'Finance', 'Customer Support', 'Operations'];

        $departments = [];

        foreach ($names as $name) {
            $departments[$name] = Department::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['slug' => Str::slug($name)],
            );
        }

        return $departments;
    }

    /**
     * @return array<string, Position>
     */
    private function seedPositions(Tenant $tenant): array
    {
        $names = [
            'HR Manager', 'HR Officer', 'Recruiter',
            'Engineering Line Manager', 'Software Engineer', 'Senior Software Engineer',
            'Finance Analyst', 'Marketing Specialist', 'Customer Support Agent', 'Operations Lead',
        ];

        $positions = [];

        foreach ($names as $name) {
            $positions[$name] = Position::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['slug' => Str::slug($name)],
            );
        }

        return $positions;
    }

    /**
     * @return array<string, Location>
     */
    private function seedLocations(Tenant $tenant): array
    {
        $names = ['Lagos HQ', 'Abuja Office', 'Remote'];

        $locations = [];

        foreach ($names as $name) {
            $locations[$name] = Location::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['slug' => Str::slug($name)],
            );
        }

        return $locations;
    }

    /**
     * 12 employees: the 4 demo-role users (HR Manager, HR Officer, Line
     * Manager, Employee) linked to real Employee records via user_id so
     * their own dashboard/leave/document views have real data, plus 8
     * additional employees rounding out departments/manager relationships,
     * including one Inactive example. Keys are stable labels used by the
     * leave/document/policy seed steps below, not persisted anywhere.
     *
     * @return array<string, Employee>
     */
    private function seedEmployees(Tenant $tenant, array $departments, array $positions, array $locations): array
    {
        $users = User::query()->where('tenant_id', $tenant->id)->get()->keyBy('email');

        $make = function (
            string $key,
            string $number,
            string $firstName,
            string $lastName,
            string $department,
            string $position,
            string $location,
            ?string $managerKey,
            array &$employees,
            ?string $linkedEmail = null,
            EmployeeStatus $status = EmployeeStatus::Active,
        ) use ($tenant, $departments, $positions, $locations, $users): Employee {
            $linkedUser = $linkedEmail !== null ? $users->get($linkedEmail) : null;

            $employee = Employee::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'employee_number' => $number],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'work_email' => $linkedUser?->email ?? Str::slug("{$firstName}.{$lastName}").'@uesl.peopleos.test',
                    'status' => $status,
                    'employment_type' => EmploymentType::FullTime,
                    'department_id' => $departments[$department]->id,
                    'position_id' => $positions[$position]->id,
                    'location_id' => $locations[$location]->id,
                    'start_date' => now()->subYears(2)->format('Y-m-d'),
                ],
            );

            if ($linkedUser !== null && $employee->user_id === null) {
                $employee->forceFill([
                    'user_id' => $linkedUser->id,
                    'linked_at' => now(),
                ])->save();
            }

            $employees[$key] = $employee;

            return $employee;
        };

        $employees = [];

        $make('hr_manager', 'EMP-90001', 'Ngozi', 'Eze', 'Human Resources', 'HR Manager', 'Lagos HQ', null, $employees, 'hr.manager@uesl.peopleos.test');
        $make('hr_officer', 'EMP-90002', 'Aisha', 'Bello', 'Human Resources', 'HR Officer', 'Lagos HQ', 'hr_manager', $employees, 'hr.officer@uesl.peopleos.test');
        $make('line_manager', 'EMP-90003', 'Tunde', 'Adeyemi', 'Engineering', 'Engineering Line Manager', 'Lagos HQ', null, $employees, 'line.manager@uesl.peopleos.test');
        $make('employee', 'EMP-90004', 'Chidi', 'Okafor', 'Engineering', 'Software Engineer', 'Lagos HQ', 'line_manager', $employees, 'employee@uesl.peopleos.test');

        $make('femi', 'EMP-90005', 'Femi', 'Adisa', 'Engineering', 'Software Engineer', 'Abuja Office', 'line_manager', $employees);
        $make('grace', 'EMP-90006', 'Grace', 'Nwosu', 'Engineering', 'Senior Software Engineer', 'Remote', 'line_manager', $employees);
        $make('ibrahim', 'EMP-90007', 'Ibrahim', 'Sule', 'Finance', 'Finance Analyst', 'Lagos HQ', 'hr_manager', $employees);
        $make('blessing', 'EMP-90008', 'Blessing', 'Okon', 'Operations', 'Marketing Specialist', 'Abuja Office', 'hr_manager', $employees);
        $make('emeka', 'EMP-90009', 'Emeka', 'Nwachukwu', 'Customer Support', 'Customer Support Agent', 'Remote', 'hr_officer', $employees);
        $make('fatima', 'EMP-90010', 'Fatima', 'Yusuf', 'Human Resources', 'Recruiter', 'Lagos HQ', 'hr_manager', $employees);
        $make('segun', 'EMP-90011', 'Segun', 'Okafor', 'Operations', 'Operations Lead', 'Lagos HQ', null, $employees);
        $make('david', 'EMP-90012', 'David', 'Essien', 'Operations', 'Operations Lead', 'Lagos HQ', 'segun', $employees, null, EmployeeStatus::Inactive);

        // manager_employee_id needs the referenced employees to already
        // exist, so it's resolved in a second pass rather than inline.
        $managerKeys = [
            'hr_officer' => 'hr_manager',
            'employee' => 'line_manager',
            'femi' => 'line_manager',
            'grace' => 'line_manager',
            'ibrahim' => 'hr_manager',
            'blessing' => 'hr_manager',
            'emeka' => 'hr_officer',
            'fatima' => 'hr_manager',
            'david' => 'segun',
        ];

        foreach ($managerKeys as $key => $managerKey) {
            if ($employees[$key]->manager_employee_id !== $employees[$managerKey]->id) {
                $employees[$key]->forceFill(['manager_employee_id' => $employees[$managerKey]->id])->save();
            }
        }

        return $employees;
    }

    /**
     * @return array<string, LeaveType>
     */
    private function seedLeaveTypes(Tenant $tenant): array
    {
        $annual = LeaveType::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Annual Leave'],
            [
                'slug' => 'annual-leave',
                'is_paid' => true,
                'requires_approval' => true,
                'requires_document' => false,
                'max_days_per_year' => 20,
                'status' => LeaveTypeStatus::Active,
            ],
        );

        $sick = LeaveType::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Sick Leave'],
            [
                'slug' => 'sick-leave',
                'is_paid' => true,
                'requires_approval' => true,
                'requires_document' => true,
                'max_days_per_year' => 10,
                'status' => LeaveTypeStatus::Active,
            ],
        );

        $unpaid = LeaveType::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Unpaid Leave'],
            [
                'slug' => 'unpaid-leave',
                'is_paid' => false,
                'requires_approval' => true,
                'requires_document' => false,
                'max_days_per_year' => null,
                'status' => LeaveTypeStatus::Active,
            ],
        );

        return ['annual' => $annual, 'sick' => $sick, 'unpaid' => $unpaid];
    }

    /**
     * Balances are seeded for every employee for the two capped leave
     * types (Annual, Sick) — Unpaid Leave is uncapped and deliberately
     * has no balance row, matching how unpaid leave is treated
     * everywhere else in the app. used_days/pending_days on the three
     * employees involved in the seeded requests below are kept in sync
     * with those requests' status and total_days so nothing looks
     * inconsistent on the Leave dashboard.
     */
    private function seedLeaveBalancesAndRequests(Tenant $tenant, array $employees, array $leaveTypes): void
    {
        $year = (int) now()->year;

        foreach ($employees as $employee) {
            foreach (['annual' => 20, 'sick' => 10] as $key => $entitlement) {
                LeaveBalance::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveTypes[$key]->id,
                        'year' => $year,
                    ],
                    [
                        'entitlement_days' => $entitlement,
                        'used_days' => 0,
                        'pending_days' => 0,
                        'carried_forward_days' => 0,
                        'adjustment_days' => 0,
                    ],
                );
            }
        }

        $hrManagerUserId = $employees['hr_manager']->user_id;
        $hrOfficerUserId = $employees['hr_officer']->user_id;

        // Pending — Chidi Okafor, a direct report of the Line Manager
        // demo user, so Tunde Adeyemi (Line Manager) has a real request
        // to approve during the live smoke test.
        $pending = LeaveRequest::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'employee_id' => $employees['employee']->id,
                'leave_type_id' => $leaveTypes['annual']->id,
                'start_date' => now()->addWeek()->format('Y-m-d'),
            ],
            [
                'end_date' => now()->addWeek()->addDays(1)->format('Y-m-d'),
                'total_days' => 2,
                'reason' => 'Family event out of town.',
                'status' => LeaveRequestStatus::Pending,
                'submitted_at' => now(),
            ],
        );

        LeaveBalance::query()
            ->where('tenant_id', $tenant->id)
            ->where('employee_id', $employees['employee']->id)
            ->where('leave_type_id', $leaveTypes['annual']->id)
            ->where('year', $year)
            ->update(['pending_days' => $pending->total_days]);

        // Approved — Grace Nwosu, approved by the HR Officer.
        $approved = LeaveRequest::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'employee_id' => $employees['grace']->id,
                'leave_type_id' => $leaveTypes['annual']->id,
                'start_date' => now()->subWeeks(2)->format('Y-m-d'),
            ],
            [
                'end_date' => now()->subWeeks(2)->addDays(2)->format('Y-m-d'),
                'total_days' => 3,
                'reason' => 'Annual leave.',
                'status' => LeaveRequestStatus::Approved,
                'submitted_at' => now()->subWeeks(3),
                'approved_by' => $hrOfficerUserId,
                'approved_at' => now()->subWeeks(2)->subDay(),
            ],
        );

        LeaveBalance::query()
            ->where('tenant_id', $tenant->id)
            ->where('employee_id', $employees['grace']->id)
            ->where('leave_type_id', $leaveTypes['annual']->id)
            ->where('year', $year)
            ->update(['used_days' => $approved->total_days]);

        // Rejected — Ibrahim Sule, rejected by the HR Manager.
        LeaveRequest::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'employee_id' => $employees['ibrahim']->id,
                'leave_type_id' => $leaveTypes['sick']->id,
                'start_date' => now()->subWeek()->format('Y-m-d'),
            ],
            [
                'end_date' => now()->subWeek()->addDays(4)->format('Y-m-d'),
                'total_days' => 5,
                'reason' => 'Extended sick leave.',
                'status' => LeaveRequestStatus::Rejected,
                'submitted_at' => now()->subWeeks(2),
                'rejected_by' => $hrManagerUserId,
                'rejected_at' => now()->subWeek()->subDay(),
                'rejection_reason' => 'Missing supporting medical documentation.',
            ],
        );
    }

    /**
     * @return array<string, DocumentCategory>
     */
    private function seedDocumentCategories(Tenant $tenant): array
    {
        $general = DocumentCategory::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'General Documents'],
            [
                'slug' => 'general-documents',
                'applies_to' => DocumentAppliesTo::Employee,
                'is_sensitive' => false,
                'is_required' => false,
                'requires_expiry_date' => false,
                'status' => DocumentCategoryStatus::Active,
            ],
        );

        $contracts = DocumentCategory::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Contracts'],
            [
                'slug' => 'contracts',
                'applies_to' => DocumentAppliesTo::Employee,
                'is_sensitive' => true,
                'is_required' => true,
                'requires_expiry_date' => false,
                'status' => DocumentCategoryStatus::Active,
            ],
        );

        $certifications = DocumentCategory::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Certifications'],
            [
                'slug' => 'certifications',
                'applies_to' => DocumentAppliesTo::Employee,
                'is_sensitive' => false,
                'is_required' => false,
                'requires_expiry_date' => true,
                'status' => DocumentCategoryStatus::Active,
            ],
        );

        return ['general' => $general, 'contracts' => $contracts, 'certifications' => $certifications];
    }

    /**
     * Files are written to the private `local` disk only — the same
     * safe pattern EmployeeDocumentFactory already uses in tests. No
     * public disk, no real private data, no exposed storage path.
     */
    private function seedDocuments(Tenant $tenant, array $employees, array $categories): void
    {
        $hrManagerUserId = $employees['hr_manager']->user_id;

        $documents = [
            [
                'employee' => 'employee',
                'category' => 'general',
                'title' => 'Offer Letter',
                'is_sensitive' => false,
                'expiry_date' => null,
            ],
            [
                'employee' => 'employee',
                'category' => 'contracts',
                'title' => 'Employment Contract',
                'is_sensitive' => true,
                'expiry_date' => null,
            ],
            [
                'employee' => 'grace',
                'category' => 'certifications',
                'title' => 'AWS Certified Solutions Architect',
                'is_sensitive' => false,
                'expiry_date' => now()->addMonths(8)->format('Y-m-d'),
            ],
            [
                'employee' => 'ibrahim',
                'category' => 'certifications',
                'title' => 'Work Permit',
                'is_sensitive' => false,
                'expiry_date' => now()->addDays(20)->format('Y-m-d'),
            ],
        ];

        foreach ($documents as $spec) {
            $employee = $employees[$spec['employee']];

            if (EmployeeDocument::query()
                ->where('tenant_id', $tenant->id)
                ->where('employee_id', $employee->id)
                ->where('title', $spec['title'])
                ->exists()) {
                continue;
            }

            $storedFilename = Str::random(40).'.pdf';
            $storagePath = 'employee-documents/demo/'.$storedFilename;

            Storage::disk('local')->put($storagePath, 'Demo PeopleOS document — not a real file.');

            EmployeeDocument::query()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'document_category_id' => $categories[$spec['category']]->id,
                'title' => $spec['title'],
                'original_filename' => Str::slug($spec['title']).'.pdf',
                'stored_filename' => $storedFilename,
                'storage_disk' => 'local',
                'storage_path' => $storagePath,
                'mime_type' => 'application/pdf',
                'file_extension' => 'pdf',
                'file_size' => 24576,
                'checksum' => hash('sha256', $storedFilename),
                'status' => DocumentStatus::Active,
                'is_sensitive' => $spec['is_sensitive'],
                'expiry_date' => $spec['expiry_date'],
                'uploaded_by' => $hrManagerUserId,
            ]);
        }
    }

    /**
     * Three policies covering all five requested acknowledgement states:
     * a Draft policy (no published version yet), a Published policy with
     * no assignments, and a published + Assigned policy whose
     * acknowledgement rows are a mix of Pending and Acknowledged —
     * covering "assigned", "pending acknowledgement" and "acknowledged"
     * in one realistic record rather than three near-duplicate policies.
     */
    private function seedPolicies(Tenant $tenant, array $employees): void
    {
        $hrManagerUserId = $employees['hr_manager']->user_id;

        // Draft — no published version.
        $draftPolicy = Policy::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Remote Work Policy'],
            [
                'slug' => 'remote-work-policy',
                'code' => 'POL-REMOTE-WORK',
                'status' => PolicyStatus::Draft,
            ],
        );

        PolicyVersion::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'policy_id' => $draftPolicy->id, 'version_number' => 1],
            [
                'title' => 'Remote Work Policy v1 (draft)',
                'summary' => 'Draft guidelines for remote and hybrid work arrangements.',
                'content' => 'This policy is still under review and has not been published yet.',
                'status' => PolicyStatus::Draft,
            ],
        );

        // Published — no assignments yet.
        $publishedPolicy = Policy::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Code of Conduct'],
            [
                'slug' => 'code-of-conduct',
                'code' => 'POL-CONDUCT',
                'status' => PolicyStatus::Published,
            ],
        );

        $publishedVersion = PolicyVersion::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'policy_id' => $publishedPolicy->id, 'version_number' => 1],
            [
                'title' => 'Code of Conduct v1',
                'summary' => 'Expected standards of conduct for all employees.',
                'content' => 'All employees are expected to act with integrity, respect, and professionalism.',
                'status' => PolicyStatus::Published,
                'published_by' => $hrManagerUserId,
                'published_at' => now()->subMonths(3),
            ],
        );

        if ($publishedPolicy->current_version_id === null) {
            $publishedPolicy->forceFill(['current_version_id' => $publishedVersion->id])->save();
        }

        // Published + assigned — mixed pending/acknowledged.
        $assignedPolicy = Policy::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'title' => 'Data Protection Policy'],
            [
                'slug' => 'data-protection-policy',
                'code' => 'POL-DATA-PROTECTION',
                'status' => PolicyStatus::Published,
            ],
        );

        $assignedVersion = PolicyVersion::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'policy_id' => $assignedPolicy->id, 'version_number' => 1],
            [
                'title' => 'Data Protection Policy v1',
                'summary' => 'How employee and customer data must be handled.',
                'content' => 'Personal data must only be accessed on a need-to-know basis and never shared outside approved systems.',
                'status' => PolicyStatus::Published,
                'published_by' => $hrManagerUserId,
                'published_at' => now()->subMonth(),
            ],
        );

        if ($assignedPolicy->current_version_id === null) {
            $assignedPolicy->forceFill(['current_version_id' => $assignedVersion->id])->save();
        }

        $assignments = [
            'employee' => AcknowledgementStatus::Pending,
            'grace' => AcknowledgementStatus::Pending,
            'ibrahim' => AcknowledgementStatus::Acknowledged,
        ];

        foreach ($assignments as $employeeKey => $status) {
            PolicyAcknowledgement::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'policy_id' => $assignedPolicy->id,
                    'policy_version_id' => $assignedVersion->id,
                    'employee_id' => $employees[$employeeKey]->id,
                ],
                [
                    'assigned_by' => $hrManagerUserId,
                    'assigned_at' => now()->subWeeks(3),
                    'acknowledgement_status' => $status,
                    'acknowledged_at' => $status === AcknowledgementStatus::Acknowledged ? now()->subWeeks(2) : null,
                ],
            );
        }
    }
}
