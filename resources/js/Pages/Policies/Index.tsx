import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PaginatedResponse, Policy } from '@/types/policy';

const statusTone: Record<Policy['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    published: 'success',
    archived: 'neutral',
};

/**
 * Policy data is fetched client-side from the existing, already-tested
 * /api/v1/policies endpoint (Checkpoint 10) — see PolicyUiController and
 * docs/architecture.md. "Current version" is shown only as a
 * present/absent indicator here (derived from current_version_id alone)
 * — the real version number/content is fetched on the detail page via
 * the new /api/v1/policies/{policy}/versions endpoint, avoiding an N+1
 * fetch per row on this list.
 */
export default function PoliciesIndex() {
    const [policies, setPolicies] = useState<Policy[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<Policy>>('/policies')
            .then((response) => setPolicies(response.data.data))
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

    return (
        <AppLayout>
            <Head title="Policies" />

            <PageHeader
                title="Policies"
                description="Tenant policy library"
                actions={
                    <PermissionGate permission="policies.create">
                        <Link
                            href="/policies/create"
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            Create policy
                        </Link>
                    </PermissionGate>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {policies === null && !error && <LoadingState label="Loading policies…" />}

            {policies !== null && policies.length === 0 && (
                <EmptyState title="No policies yet" description="Policies created for this tenant will appear here." />
            )}

            {policies !== null && policies.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Code</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Category</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Effective</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Review</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Version</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {policies.map((policy) => (
                                <tr key={policy.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link href={`/policies/${policy.id}`} className="hover:text-indigo-600">
                                            {policy.title}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{policy.code ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[policy.status]}>{policy.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{policy.category ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{policy.effective_date ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{policy.review_date ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {policy.current_version_id ? 'Published' : '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <Link href={`/policies/${policy.id}`} className="text-indigo-600 hover:text-indigo-500">
                                                View
                                            </Link>
                                            <PermissionGate permission="policies.update">
                                                <Link href={`/policies/${policy.id}/edit`} className="text-indigo-600 hover:text-indigo-500">
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="policies.publish">
                                                <Link href={`/policies/${policy.id}`} className="text-indigo-600 hover:text-indigo-500">
                                                    Publish
                                                </Link>
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
