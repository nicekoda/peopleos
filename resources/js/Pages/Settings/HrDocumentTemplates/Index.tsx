import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { HR_DOCUMENT_TYPE_LABELS, HrDocumentTemplate, HrDocumentTemplateStatus, PaginatedResponse } from '@/types/hrDocument';

const statusTone: Record<HrDocumentTemplateStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
};

/**
 * Template data is fetched client-side from /api/v1/hr-document-templates.
 * HrDocumentTemplate already uses BelongsToTenant, so this list can never
 * include another tenant's templates. "Archive" (not "Delete") because
 * the backend action is soft-delete-only, same convention as Document
 * Categories.
 */
export default function SettingsHrDocumentTemplatesIndex() {
    const [templates, setTemplates] = useState<HrDocumentTemplate[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<HrDocumentTemplate>>('/hr-document-templates')
            .then((response) => setTemplates(response.data.data))
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

    const handleArchive = (template: HrDocumentTemplate) => {
        if (!window.confirm(`Archive "${template.title}"? It will no longer be selectable for new document generation.`)) {
            return;
        }

        setArchivingId(template.id);
        setError(null);

        api.delete(`/hr-document-templates/${template.id}`)
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
            <Head title="HR Document Templates" />

            <PageHeader
                title="HR Document Templates"
                description="Manage templates used to generate employee letters and documents."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="hr_document_templates.create">
                            <Link
                                href="/settings/hr-document-templates/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add template
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {templates === null && !error && <LoadingState label="Loading HR document templates…" />}

            {templates !== null && templates.length === 0 && (
                <EmptyState title="No HR document templates yet" description="Templates created for this tenant will appear here." />
            )}

            {templates !== null && templates.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Created</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {templates.map((template) => (
                                <tr key={template.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{template.title}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {HR_DOCUMENT_TYPE_LABELS[template.document_type]}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[template.status]}>{template.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{template.created_at?.slice(0, 10)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="hr_document_templates.update">
                                                <Link
                                                    href={`/settings/hr-document-templates/${template.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="hr_document_templates.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(template)}
                                                    disabled={archivingId === template.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === template.id ? 'Archiving…' : 'Archive'}
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
