import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee, EmployeeFormPayload } from '@/types/employee';

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
    start_date: '',
    probation_end_date: '',
    confirmation_date: '',
};

/**
 * Refinement 3 — the payload sent to POST /employees is built field by
 * field from `emptyForm`'s shape, never by spreading a broader object.
 * department_id/location_id/position_id (no lookup API exists yet —
 * see docs/api.md) and manager_employee_id/user-link/tenant_id/
 * created_by/updated_by (structurally rejected by StoreEmployeeRequest
 * itself, see docs/security.md) are never fields on this form at all.
 */
export default function EmployeesCreate() {
    const [form, setForm] = useState<EmployeeFormPayload>(emptyForm);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState<string | null>(null);

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
        // string differently from an absent key for some validators.
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
