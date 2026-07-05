import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Location, LocationStatus, PaginatedResponse } from '@/types/location';

const statusTone: Record<LocationStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
};

/**
 * Checkpoint 32 — Location data is fetched client-side from
 * /api/v1/locations. Location already uses BelongsToTenant, so this
 * list can never include another tenant's locations via the standard
 * two-layer pattern. "Archive" (not "Delete") because the backend
 * action is soft-delete-only.
 */
export default function SettingsLocationsIndex() {
    const [locations, setLocations] = useState<Location[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<Location>>('/locations')
            .then((response) => setLocations(response.data.data))
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

    const handleArchive = (location: Location) => {
        if (!window.confirm(`Archive "${location.name}"? It will no longer be selectable for employee records.`)) {
            return;
        }

        setArchivingId(location.id);
        setError(null);

        api.delete(`/locations/${location.id}`)
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
            <Head title="Locations" />

            <PageHeader
                title="Locations"
                description="Manage the office/location catalog used across employee records."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="locations.create">
                            <Link
                                href="/settings/locations/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add location
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {locations === null && !error && <LoadingState label="Loading locations…" />}

            {locations !== null && locations.length === 0 && (
                <EmptyState title="No locations yet" description="Locations created for this tenant will appear here." />
            )}

            {locations !== null && locations.length > 0 && (
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
                            {locations.map((location) => (
                                <tr key={location.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{location.name}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[location.status]}>{location.status}</Badge>
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{location.description ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{location.created_at?.slice(0, 10)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="locations.update">
                                                <Link
                                                    href={`/settings/locations/${location.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="locations.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(location)}
                                                    disabled={archivingId === location.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === location.id ? 'Archiving…' : 'Archive'}
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
