import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LifecycleProcess, LifecycleProcessStatus, PaginatedResponse } from '@/types/lifecycle';

const statusTone: Record<LifecycleProcessStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    in_progress: 'warning',
    completed: 'success',
    cancelled: 'danger',
};

const typeLabel: Record<string, string> = {
    onboarding: 'Onboarding',
    offboarding: 'Offboarding',
};

function isOverdue(process: LifecycleProcess): boolean {
    if (!process.due_date || process.status === 'completed' || process.status === 'cancelled') {
        return false;
    }

    return new Date(process.due_date) < new Date(new Date().toDateString());
}

/**
 * Checkpoint 33 — process list, fetched client-side from
 * /api/v1/lifecycle-processes. The backend already scopes rows to what
 * the caller may see (LifecycleVisibilityService) — this page never
 * applies its own visibility filtering, only an optional employeeId
 * query-string filter (a display convenience when arriving from an
 * Employee detail page's "View Lifecycle" link, not a security
 * boundary).
 */
export default function LifecycleIndex() {
    const [processes, setProcesses] = useState<LifecycleProcess[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const employeeIdFilter = useMemo(() => new URLSearchParams(window.location.search).get('employeeId'), []);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<LifecycleProcess>>('/lifecycle-processes')
            .then((response) => setProcesses(response.data.data))
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

    const visibleProcesses = employeeIdFilter
        ? processes?.filter((process) => process.employee_id === employeeIdFilter) ?? null
        : processes;

    return (
        <AppLayout>
            <Head title="Onboarding & Offboarding" />

            <PageHeader
                title="Onboarding & Offboarding"
                description="Track lifecycle processes and their tasks for employees joining or leaving."
                actions={
                    <PermissionGate permission="lifecycle.create">
                        <Link
                            href="/lifecycle/create"
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            New process
                        </Link>
                    </PermissionGate>
                }
            />

            {error && <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>}

            {processes === null && !error && <LoadingState label="Loading lifecycle processes…" />}

            {visibleProcesses !== null && visibleProcesses.length === 0 && (
                <EmptyState
                    title="No lifecycle processes yet"
                    description="Onboarding and offboarding processes created for this tenant will appear here."
                />
            )}

            {visibleProcesses !== null && visibleProcesses.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Employee</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Due date</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {visibleProcesses.map((process) => (
                                <tr key={process.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link href={`/lifecycle/${process.id}`} className="text-indigo-600 hover:text-indigo-500">
                                            {process.employee?.full_name ?? '—'}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-700">{typeLabel[process.type]}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[process.status]}>{process.status.replace('_', ' ')}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {process.due_date ? (
                                            <Badge tone={isOverdue(process) ? 'danger' : 'neutral'}>{process.due_date}</Badge>
                                        ) : (
                                            <span className="text-slate-400">—</span>
                                        )}
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
