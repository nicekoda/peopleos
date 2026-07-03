import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee, EmployeeFormPayload } from '@/types/employee';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    employeeId: string;
}

function toFormPayload(employee: Employee): EmployeeFormPayload {
    return {
        employee_number: employee.employee_number,
        first_name: employee.first_name,
        middle_name: employee.middle_name ?? '',
        last_name: employee.last_name,
        preferred_name: employee.preferred_name ?? '',
        work_email: employee.work_email ?? '',
        personal_email: employee.personal_email ?? '',
        phone: employee.phone ?? '',
        employment_type: employee.employment_type,
        status: employee.status,
        start_date: employee.start_date ?? '',
        probation_end_date: employee.probation_end_date ?? '',
        confirmation_date: employee.confirmation_date ?? '',
    };
}

/**
 * manager_employee_id and any user-link field are never loaded into
 * this form and never sent back — this is the generic edit form only;
 * manager assignment and user linking have their own dedicated
 * endpoints and are explicitly out of scope for this UI (see
 * docs/security.md). Refinement 3 applies here too: the PATCH payload
 * is built from this same allowlisted shape, never by spreading the
 * fetched Employee object.
 */
export default function EmployeesEdit() {
    const { employeeId } = usePage<EditProps>().props;

    const [form, setForm] = useState<EmployeeFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [success, setSuccess] = useState(false);

    useEffect(() => {
        api.get<{ data: Employee }>(`/employees/${employeeId}`)
            .then((response) => setForm(toFormPayload(response.data.data)))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [employeeId]);

    const set = <K extends keyof EmployeeFormPayload>(key: K, value: EmployeeFormPayload[K]) => {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!form) {
            return;
        }

        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));

        api.patch(`/employees/${employeeId}`, payload)
            .then(() => {
                setSuccess(true);
                window.setTimeout(() => router.visit(`/employees/${employeeId}`), 1200);
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

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Edit employee" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit employee" />
                <LoadingState label="Loading employee…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit employee" />

            <PageHeader title="Edit employee" description={`#${form.employee_number}`} />

            {success && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">
                    Employee updated. Redirecting…
                </div>
            )}
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
                            {submitting ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
