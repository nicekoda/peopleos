import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { DocumentCategory, DocumentCategoryStatus, PaginatedResponse } from '@/types/documentCategory';

const statusTone: Record<DocumentCategoryStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
};

/**
 * Document category data is fetched client-side from the existing,
 * already-tested /api/v1/document-categories endpoint (Checkpoint 9).
 * DocumentCategory already uses BelongsToTenant, so this list can never
 * include another tenant's categories via the standard two-layer
 * pattern (global scope + the controller's explicit check on
 * edit/archive). "Archive" (not "Delete") because the backend action
 * is soft-delete-only.
 */
export default function SettingsDocumentCategoriesIndex() {
    const [categories, setCategories] = useState<DocumentCategory[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<DocumentCategory>>('/document-categories')
            .then((response) => setCategories(response.data.data))
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
     * Refinement 5 — no optimistic removal. The row disappears only
     * after a full refetch confirms the backend actually archived it;
     * 403/404 surface as a safe inline error, never a raw response body.
     */
    const handleArchive = (category: DocumentCategory) => {
        if (!window.confirm(`Archive "${category.name}"? It will no longer be selectable for new document uploads.`)) {
            return;
        }

        setArchivingId(category.id);
        setError(null);

        api.delete(`/document-categories/${category.id}`)
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
            <Head title="Document Categories" />

            <PageHeader
                title="Document Categories"
                description="Manage the document category catalog used across employee document uploads."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="document_categories.create">
                            <Link
                                href="/settings/document-categories/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add category
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {categories === null && !error && <LoadingState label="Loading document categories…" />}

            {categories !== null && categories.length === 0 && (
                <EmptyState title="No document categories yet" description="Categories created for this tenant will appear here." />
            )}

            {categories !== null && categories.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Name</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Flags</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Description</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Created</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {categories.map((category) => (
                                <tr key={category.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{category.name}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[category.status]}>{category.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <div className="flex gap-2">
                                            {category.is_sensitive && <Badge tone="warning">Sensitive</Badge>}
                                            {category.requires_expiry_date && <Badge tone="neutral">Expiry required</Badge>}
                                        </div>
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{category.description ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{category.created_at?.slice(0, 10)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="document_categories.update">
                                                <Link
                                                    href={`/settings/document-categories/${category.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="document_categories.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(category)}
                                                    disabled={archivingId === category.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === category.id ? 'Archiving…' : 'Archive'}
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
