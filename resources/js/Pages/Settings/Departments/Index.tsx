import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Department, DepartmentStatus, PaginatedResponse } from '@/types/department';

const statusTone: Record<DepartmentStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
};

/**
 * Checkpoint 32 — Department data is fetched client-side from
 * /api/v1/departments. Department already uses BelongsToTenant, so
 * this list can never include another tenant's departments via the
 * standard two-layer pattern. "Archive" (not "Delete") because the
 * backend action is soft-delete-only.
 */
export default function SettingsDepartmentsIndex() {
    const [departments, setDepartments] = useState<Department[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<Department>>('/departments')
            .then((response) => setDepartments(response.data.data))
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
     * No optimistic removal — the row disappears only after a full
     * refetch confirms the backend actually archived it.
     */
    const handleArchive = (department: Department) => {
        if (!window.confirm(`Archive "${department.name}"? It will no longer be selectable for employee records.`)) {
            return;
        }

        setArchivingId(department.id);
        setError(null);

        api.delete(`/departments/${department.id}`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setArchivingId(null));
    };

    return (
        <AppLayout>
            <Head title="Departments" />

            <PageHeader
                title="Departments"
                description="Manage the department catalog used across employee records."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="departments.create">
                            <Link
                                href="/settings/departments/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add department
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {departments === null && !error && <LoadingState label="Loading departments…" />}

            {departments !== null && departments.length === 0 && (
                <EmptyState title="No departments yet" description="Departments created for this tenant will appear here." />
            )}

            {departments !== null && departments.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Name</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Description</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Created</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {departments.map((department) => (
                                <tr key={department.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{department.name}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[department.status]}>{department.status}</Badge>
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{department.description ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{department.created_at?.slice(0, 10)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="departments.update">
                                                <Link
                                                    href={`/settings/departments/${department.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="departments.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(department)}
                                                    disabled={archivingId === department.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === department.id ? 'Archiving…' : 'Archive'}
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
