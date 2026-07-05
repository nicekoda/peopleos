import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee } from '@/types/employee';
import { PaginatedResponse as EmployeePaginatedResponse } from '@/types/department';
import { LifecycleProcess, LifecycleProcessFormPayload } from '@/types/lifecycle';

/**
 * Checkpoint 33 — employee_id/type/started_at/due_date only. status is
 * never a Create field — a new process always starts as draft, set by
 * the backend. employeeId/type query-string params (from an Employee
 * detail page's "Start Onboarding"/"Start Offboarding" link) only
 * pre-fill the form; the actual employee_id/type sent to the backend is
 * always whatever this form currently holds, never trusted from the URL
 * alone.
 */
export default function LifecycleCreate() {
    const params = useMemo(() => new URLSearchParams(window.location.search), []);

    const [form, setForm] = useState<LifecycleProcessFormPayload>({
        employee_id: params.get('employeeId') ?? '',
        type: (params.get('type') as LifecycleProcessFormPayload['type']) ?? '',
        started_at: '',
        due_date: '',
    });
    const [employees, setEmployees] = useState<Employee[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<EmployeePaginatedResponse<Employee>>('/employees')
            .then((response) => setEmployees(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, []);

    const set = <K extends keyof LifecycleProcessFormPayload>(key: K, value: LifecycleProcessFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            employee_id: form.employee_id,
            type: form.type,
            ...(form.started_at ? { started_at: form.started_at } : {}),
            ...(form.due_date ? { due_date: form.due_date } : {}),
        };

        api.post<{ data: LifecycleProcess }>('/lifecycle-processes', payload)
            .then((response) => {
                router.visit(`/lifecycle/${response.data.data.id}`);
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

    return (
        <AppLayout>
            <Head title="Start lifecycle process" />

            <PageHeader
                title="Start onboarding or offboarding"
                description="Every process starts as a draft. Add tasks once it's created."
                actions={
                    <Link href="/lifecycle" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Lifecycle
                    </Link>
                }
            />

            {loadError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Employee"
                            name="employee_id"
                            required
                            value={form.employee_id}
                            onChange={(e) => set('employee_id', e.target.value)}
                            error={fieldError('employee_id')}
                        >
                            <option value="">— Select an employee —</option>
                            {(employees ?? []).map((employee) => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.full_name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Process type"
                            name="type"
                            required
                            value={form.type}
                            onChange={(e) => set('type', e.target.value as LifecycleProcessFormPayload['type'])}
                            error={fieldError('type')}
                        >
                            <option value="">— Select a type —</option>
                            <option value="onboarding">Onboarding</option>
                            <option value="offboarding">Offboarding</option>
                        </SelectField>
                        <InputField
                            label="Start date"
                            name="started_at"
                            type="date"
                            value={form.started_at}
                            onChange={(e) => set('started_at', e.target.value)}
                            error={fieldError('started_at')}
                        />
                        <InputField
                            label="Due date"
                            name="due_date"
                            type="date"
                            value={form.due_date}
                            onChange={(e) => set('due_date', e.target.value)}
                            error={fieldError('due_date')}
                        />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting || employees === null}>
                            {submitting ? 'Creating…' : 'Create process'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
