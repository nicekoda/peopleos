import { Head, Link, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Policy } from '@/types/policy';
import { Employee, PaginatedResponse } from '@/types/employee';
import { PageProps } from '@/types';

interface AssignProps extends PageProps {
    policyId: string;
}

/**
 * Assignment payload sends only `employee_ids` (selected from the
 * fetched /api/v1/employees list — never free-text) and an optional
 * `due_date`, matching AssignPolicyRequest exactly. No policy_id,
 * tenant_id, assigned_by, assigned_at, or acknowledgement_status are
 * ever fields here; policy_version_id is never sent either — the
 * backend always assigns against the policy's own current_version_id,
 * so there's no version to select. The employee list is naturally
 * tenant-scoped (the same /api/v1/employees endpoint every other module
 * uses), so cross-tenant assignment isn't something this form could even
 * attempt — the backend independently re-validates every ID regardless.
 */
export default function PolicyAssign() {
    const { policyId } = usePage<AssignProps>().props;

    const [policy, setPolicy] = useState<Policy | null>(null);
    const [employees, setEmployees] = useState<Employee[] | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);

    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [dueDate, setDueDate] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [result, setResult] = useState<{ created: string[]; skipped_duplicates: string[] } | null>(null);

    useEffect(() => {
        Promise.all([
            api.get<{ data: Policy }>(`/policies/${policyId}`),
            api.get<PaginatedResponse<Employee>>('/employees'),
        ])
            .then(([policyResponse, employeesResponse]) => {
                setPolicy(policyResponse.data.data);
                setEmployees(employeesResponse.data.data);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [policyId]);

    const toggleEmployee = (employeeId: string) => {
        setSelectedIds((prev) => (prev.includes(employeeId) ? prev.filter((id) => id !== employeeId) : [...prev, employeeId]));
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (selectedIds.length === 0) return;

        setSubmitting(true);
        setGeneralError(null);
        setResult(null);

        api.post<{ created: string[]; skipped_duplicates: string[] }>(`/policies/${policyId}/assign`, {
            employee_ids: selectedIds,
            ...(dueDate ? { due_date: dueDate } : {}),
        })
            .then((response) => {
                setResult(response.data);
                setSelectedIds([]);
            })
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setGeneralError(apiError.message);
                }
            })
            .finally(() => setSubmitting(false));
    };

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Assign policy" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!policy || !employees) {
        return (
            <AppLayout>
                <Head title="Assign policy" />
                <LoadingState label="Loading…" />
            </AppLayout>
        );
    }

    if (!policy.current_version_id) {
        return (
            <AppLayout>
                <Head title="Assign policy" />
                <PageHeader title={`Assign "${policy.title}"`} />
                <div className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    This policy has no published version yet. Publish a version before assigning it.
                </div>
                <div className="mt-4">
                    <Link href={`/policies/${policyId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to policy
                    </Link>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Assign policy" />

            <PageHeader
                title={`Assign "${policy.title}"`}
                description="Select employees to assign the current published version to."
                actions={
                    <Link href={`/policies/${policyId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to policy
                    </Link>
                }
            />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            {result && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">
                    Assigned to {result.created.length} employee{result.created.length === 1 ? '' : 's'}.
                    {result.skipped_duplicates.length > 0 && ` ${result.skipped_duplicates.length} already had this version assigned and were skipped.`}
                </div>
            )}

            <form onSubmit={submit}>
                <Card>
                    {employees.length === 0 ? (
                        <p className="text-sm text-slate-500">No employees found in this tenant.</p>
                    ) : (
                        <div className="max-h-96 space-y-2 overflow-y-auto">
                            {employees.map((employee) => (
                                <label key={employee.id} className="flex items-center gap-3 rounded-md px-2 py-1.5 hover:bg-slate-50">
                                    <input
                                        type="checkbox"
                                        checked={selectedIds.includes(employee.id)}
                                        onChange={() => toggleEmployee(employee.id)}
                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                    />
                                    <span className="text-sm text-slate-900">
                                        {employee.full_name} <span className="text-slate-500">({employee.employee_number})</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}

                    <div className="mt-4 max-w-xs">
                        <label htmlFor="due_date" className="block text-sm font-medium text-slate-700">
                            Due date
                        </label>
                        <input
                            id="due_date"
                            type="date"
                            value={dueDate}
                            onChange={(e) => setDueDate(e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting || selectedIds.length === 0}>
                            {submitting ? 'Assigning…' : `Assign to ${selectedIds.length} employee${selectedIds.length === 1 ? '' : 's'}`}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
