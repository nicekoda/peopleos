import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LeaveType, LeaveTypeStatus, PaginatedResponse } from '@/types/leaveType';

const statusTone: Record<LeaveTypeStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
};

/**
 * Leave type data is fetched client-side from the existing,
 * already-tested /api/v1/leave-types endpoint (Checkpoint 12). LeaveType
 * already uses BelongsToTenant, so this list can never include another
 * tenant's leave types via the standard two-layer pattern. "Archive"
 * (not "Delete") because the backend action is soft-delete-only.
 * max_days_per_year of null is shown as "Unlimited" — see
 * docs/security.md for what that means for balance enforcement.
 */
export default function SettingsLeaveTypesIndex() {
    const [leaveTypes, setLeaveTypes] = useState<LeaveType[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<LeaveType>>('/leave-types')
            .then((response) => setLeaveTypes(response.data.data))
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

    const handleArchive = (leaveType: LeaveType) => {
        if (!window.confirm(`Archive "${leaveType.name}"? It will no longer be selectable for new leave requests.`)) {
            return;
        }

        setArchivingId(leaveType.id);
        setError(null);

        api.delete(`/leave-types/${leaveType.id}`)
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
            <Head title="Leave Types" />

            <PageHeader
                title="Leave Types"
                description="Manage leave types and their entitlements."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="leave_types.create">
                            <Link
                                href="/settings/leave-types/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add leave type
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {leaveTypes === null && !error && <LoadingState label="Loading leave types…" />}

            {leaveTypes !== null && leaveTypes.length === 0 && (
                <EmptyState title="No leave types yet" description="Leave types created for this tenant will appear here." />
            )}

            {leaveTypes !== null && leaveTypes.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Name</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Flags</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Max days/year</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Description</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {leaveTypes.map((leaveType) => (
                                <tr key={leaveType.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{leaveType.name}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[leaveType.status]}>{leaveType.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <div className="flex gap-2">
                                            <Badge tone={leaveType.is_paid ? 'success' : 'neutral'}>
                                                {leaveType.is_paid ? 'Paid' : 'Unpaid'}
                                            </Badge>
                                            {leaveType.requires_approval && <Badge tone="warning">Approval required</Badge>}
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {leaveType.max_days_per_year ?? 'Unlimited'}
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{leaveType.description ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="leave_types.update">
                                                <Link
                                                    href={`/settings/leave-types/${leaveType.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="leave_types.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(leaveType)}
                                                    disabled={archivingId === leaveType.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === leaveType.id ? 'Archiving…' : 'Archive'}
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
