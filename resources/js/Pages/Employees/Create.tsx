import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import { InputField, SelectField } from '@/Components/FormField';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee, EmployeeFormPayload } from '@/types/employee';
import { Department, PaginatedResponse as DepartmentPaginatedResponse } from '@/types/department';
import { Position, PaginatedResponse as PositionPaginatedResponse } from '@/types/position';
import { Location, PaginatedResponse as LocationPaginatedResponse } from '@/types/location';

const emptyForm: EmployeeFormPayload = {
    employee_number: '',
    first_name: '',
    middle_name: '',
    last_name: '',
    preferred_name: '',
    work_email: '',
    personal_email: '',
    phone: '',
    employment_type: 'full_time',
    status: 'draft',
    department_id: '',
    location_id: '',
    position_id: '',
    start_date: '',
    probation_end_date: '',
    confirmation_date: '',
};

/**
 * Refinement 3 — the payload sent to POST /employees is built field by
 * field from `emptyForm`'s shape, never by spreading a broader object.
 * manager_employee_id/user-link/tenant_id/created_by/updated_by
 * (structurally rejected by StoreEmployeeRequest itself, see
 * docs/security.md) are never fields on this form at all.
 * department_id/location_id/position_id were added Checkpoint 32, now
 * that real lookup APIs exist — only active, same-tenant options are
 * offered (tenant scoping is automatic via BelongsToTenant; "active
 * only" is a client-side filter here, matching this app's existing
 * employee-linking picker convention).
 */
export default function EmployeesCreate() {
    const [form, setForm] = useState<EmployeeFormPayload>(emptyForm);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState<string | null>(null);

    const canViewDepartments = useCan('departments.view');
    const canViewPositions = useCan('positions.view');
    const canViewLocations = useCan('locations.view');

    const [departments, setDepartments] = useState<Department[]>([]);
    const [positions, setPositions] = useState<Position[]>([]);
    const [locations, setLocations] = useState<Location[]>([]);

    useEffect(() => {
        if (!canViewDepartments) return;
        api.get<DepartmentPaginatedResponse<Department>>('/departments')
            .then((response) => setDepartments(response.data.data.filter((department) => department.status === 'active')))
            .catch(() => setDepartments([]));
    }, [canViewDepartments]);

    useEffect(() => {
        if (!canViewPositions) return;
        api.get<PositionPaginatedResponse<Position>>('/positions')
            .then((response) => setPositions(response.data.data.filter((position) => position.status === 'active')))
            .catch(() => setPositions([]));
    }, [canViewPositions]);

    useEffect(() => {
        if (!canViewLocations) return;
        api.get<LocationPaginatedResponse<Location>>('/locations')
            .then((response) => setLocations(response.data.data.filter((location) => location.status === 'active')))
            .catch(() => setLocations([]));
    }, [canViewLocations]);

    const set = <K extends keyof EmployeeFormPayload>(key: K, value: EmployeeFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        // Blank optional strings are omitted rather than sent as "" —
        // StoreEmployeeRequest's nullable/date rules treat an empty
        // string differently from an absent key for some validators. A
        // brand-new employee has no prior department/location/position
        // value to preserve, so omitting these when blank is safe here
        // (unlike Edit, which needs an explicit null — see Edit.tsx).
        const payload = Object.fromEntries(
            Object.entries(form).filter(([, value]) => value !== ''),
        );

        api.post<{ data: Employee }>('/employees', payload)
            .then((response) => {
                router.visit(`/employees/${response.data.data.id}`);
            })
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (apiError.errors) {
                    setErrors(apiError.errors);
                }
                setGeneralError(apiError.message);
            })
            .finally(() => setSubmitting(false));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    return (
        <AppLayout>
            <Head title="Add employee" />

            <PageHeader title="Add employee" description="Create a new employee record" />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <InputField
                            label="Employee number"
                            name="employee_number"
                            required
                            value={form.employee_number}
                            onChange={(e) => set('employee_number', e.target.value)}
                            error={fieldError('employee_number')}
                        />
                        <SelectField
                            label="Employment type"
                            name="employment_type"
                            required
                            value={form.employment_type}
                            onChange={(e) => set('employment_type', e.target.value as EmployeeFormPayload['employment_type'])}
                            error={fieldError('employment_type')}
                        >
                            <option value="full_time">Full time</option>
                            <option value="part_time">Part time</option>
                            <option value="contractor">Contractor</option>
                            <option value="intern">Intern</option>
                            <option value="consultant">Consultant</option>
                        </SelectField>

                        <InputField
                            label="First name"
                            name="first_name"
                            required
                            value={form.first_name}
                            onChange={(e) => set('first_name', e.target.value)}
                            error={fieldError('first_name')}
                        />
                        <InputField
                            label="Middle name"
                            name="middle_name"
                            value={form.middle_name}
                            onChange={(e) => set('middle_name', e.target.value)}
                            error={fieldError('middle_name')}
                        />
                        <InputField
                            label="Last name"
                            name="last_name"
                            required
                            value={form.last_name}
                            onChange={(e) => set('last_name', e.target.value)}
                            error={fieldError('last_name')}
                        />
                        <InputField
                            label="Preferred name"
                            name="preferred_name"
                            value={form.preferred_name}
                            onChange={(e) => set('preferred_name', e.target.value)}
                            error={fieldError('preferred_name')}
                        />

                        <InputField
                            label="Work email"
                            name="work_email"
                            type="email"
                            value={form.work_email}
                            onChange={(e) => set('work_email', e.target.value)}
                            error={fieldError('work_email')}
                        />
                        <SelectField
                            label="Status"
                            name="status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value as EmployeeFormPayload['status'])}
                            error={fieldError('status')}
                        >
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="terminated">Terminated</option>
                        </SelectField>

                        <InputField
                            label="Personal email"
                            name="personal_email"
                            type="email"
                            value={form.personal_email}
                            onChange={(e) => set('personal_email', e.target.value)}
                            error={fieldError('personal_email')}
                        />
                        <InputField
                            label="Phone"
                            name="phone"
                            value={form.phone}
                            onChange={(e) => set('phone', e.target.value)}
                            error={fieldError('phone')}
                        />

                        <SelectField
                            label="Department"
                            name="department_id"
                            value={form.department_id}
                            onChange={(e) => set('department_id', e.target.value)}
                            error={fieldError('department_id')}
                        >
                            <option value="">— None —</option>
                            {departments.map((department) => (
                                <option key={department.id} value={department.id}>
                                    {department.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Position"
                            name="position_id"
                            value={form.position_id}
                            onChange={(e) => set('position_id', e.target.value)}
                            error={fieldError('position_id')}
                        >
                            <option value="">— None —</option>
                            {positions.map((position) => (
                                <option key={position.id} value={position.id}>
                                    {position.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Location"
                            name="location_id"
                            value={form.location_id}
                            onChange={(e) => set('location_id', e.target.value)}
                            error={fieldError('location_id')}
                        >
                            <option value="">— None —</option>
                            {locations.map((location) => (
                                <option key={location.id} value={location.id}>
                                    {location.name}
                                </option>
                            ))}
                        </SelectField>

                        <InputField
                            label="Start date"
                            name="start_date"
                            type="date"
                            value={form.start_date}
                            onChange={(e) => set('start_date', e.target.value)}
                            error={fieldError('start_date')}
                        />
                        <InputField
                            label="Probation end date"
                            name="probation_end_date"
                            type="date"
                            value={form.probation_end_date}
                            onChange={(e) => set('probation_end_date', e.target.value)}
                            error={fieldError('probation_end_date')}
                        />
                        <InputField
                            label="Confirmation date"
                            name="confirmation_date"
                            type="date"
                            value={form.confirmation_date}
                            onChange={(e) => set('confirmation_date', e.target.value)}
                            error={fieldError('confirmation_date')}
                        />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Saving…' : 'Create employee'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
