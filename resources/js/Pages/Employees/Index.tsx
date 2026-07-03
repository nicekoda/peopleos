import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee, PaginatedResponse } from '@/types/employee';

const statusTone: Record<Employee['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    active: 'success',
    inactive: 'warning',
    terminated: 'danger',
};

/**
 * Employee data is fetched client-side from the existing, already-
 * tested /api/v1/employees endpoint — see EmployeeUiController and
 * docs/architecture.md for why. Sensitive fields (personal_email/phone)
 * are never requested here at all — the list deliberately doesn't show
 * them, matching your instruction not to expose sensitive fields in the
 * list view.
 */
export default function EmployeesIndex() {
    const [employees, setEmployees] = useState<Employee[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [deletingId, setDeletingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<Employee>>('/employees')
            .then((response) => setEmployees(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    /**
     * Refinement 4 — the row is only removed after the backend confirms
     * success (a full refetch, not an optimistic removal beforehand);
     * 403/404 surface as a safe inline error, never a raw API response.
     */
    const handleDelete = (employee: Employee) => {
        if (!window.confirm(`Delete ${employee.full_name}? This can be reversed by an administrator.`)) {
            return;
        }

        setDeletingId(employee.id);
        setError(null);

        api.delete(`/employees/${employee.id}`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setDeletingId(null));
    };

    return (
        <AppLayout>
            <Head title="Employees" />

            <PageHeader
                title="Employees"
                description="Employee records"
                actions={
                    <PermissionGate permission="employees.create">
                        <Link
                            href="/employees/create"
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            Add employee
                        </Link>
                    </PermissionGate>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {employees === null && !error && <LoadingState label="Loading employees…" />}

            {employees !== null && employees.length === 0 && (
                <EmptyState
                    title="No employees yet"
                    description="Employees created for this tenant will appear here."
                />
            )}

            {employees !== null && employees.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Number</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Name</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Work email</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {employees.map((employee) => (
                                <tr key={employee.id}>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{employee.employee_number}</td>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link href={`/employees/${employee.id}`} className="hover:text-indigo-600">
                                            {employee.full_name}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{employee.work_email ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{employee.employment_type.replace('_', ' ')}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[employee.status]}>{employee.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <Link href={`/employees/${employee.id}`} className="text-indigo-600 hover:text-indigo-500">
                                                View
                                            </Link>
                                            <PermissionGate permission="employees.update">
                                                <Link href={`/employees/${employee.id}/edit`} className="text-indigo-600 hover:text-indigo-500">
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="employees.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(employee)}
                                                    disabled={deletingId === employee.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {deletingId === employee.id ? 'Deleting…' : 'Delete'}
                                                </button>
                                            </PermissionGate>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
