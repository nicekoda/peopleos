import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Position, PositionStatus, PaginatedResponse } from '@/types/position';

const statusTone: Record<PositionStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
};

/**
 * Checkpoint 32 — Position data is fetched client-side from
 * /api/v1/positions. Position already uses BelongsToTenant, so this
 * list can never include another tenant's positions via the standard
 * two-layer pattern. "Archive" (not "Delete") because the backend
 * action is soft-delete-only.
 */
export default function SettingsPositionsIndex() {
    const [positions, setPositions] = useState<Position[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<Position>>('/positions')
            .then((response) => setPositions(response.data.data))
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

    const handleArchive = (position: Position) => {
        if (!window.confirm(`Archive "${position.name}"? It will no longer be selectable for employee records.`)) {
            return;
        }

        setArchivingId(position.id);
        setError(null);

        api.delete(`/positions/${position.id}`)
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
            <Head title="Positions" />

            <PageHeader
                title="Positions"
                description="Manage the job title catalog used across employee records."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="positions.create">
                            <Link
                                href="/settings/positions/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add position
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {positions === null && !error && <LoadingState label="Loading positions…" />}

            {positions !== null && positions.length === 0 && (
                <EmptyState title="No positions yet" description="Positions created for this tenant will appear here." />
            )}

            {positions !== null && positions.length > 0 && (
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
                            {positions.map((position) => (
                                <tr key={position.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{position.name}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[position.status]}>{position.status}</Badge>
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{position.description ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{position.created_at?.slice(0, 10)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="positions.update">
                                                <Link
                                                    href={`/settings/positions/${position.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="positions.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(position)}
                                                    disabled={archivingId === position.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === position.id ? 'Archiving…' : 'Archive'}
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
