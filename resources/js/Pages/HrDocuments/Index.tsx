import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { HR_DOCUMENT_TYPE_LABELS, HrGeneratedDocument, HrGeneratedDocumentStatus, PaginatedResponse } from '@/types/hrDocument';

const statusTone: Record<HrGeneratedDocumentStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    generated: 'success',
    archived: 'neutral',
};

/**
 * Checkpoint 34 — generated document list, fetched client-side from
 * /api/v1/hr-generated-documents. The optional ?employeeId= query-string
 * filter (arriving from an Employee detail page's "HR Documents" link)
 * is passed through as a real server-side filter — HrGeneratedDocumentController::index()
 * already validates it belongs to the current tenant, same as every
 * other tenant-scoped list.
 */
export default function HrDocumentsIndex() {
    const [documents, setDocuments] = useState<HrGeneratedDocument[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const employeeIdFilter = useMemo(() => new URLSearchParams(window.location.search).get('employeeId'), []);

    const load = useCallback(() => {
        setError(null);
        const query = employeeIdFilter ? `?employee_id=${encodeURIComponent(employeeIdFilter)}` : '';

        api.get<PaginatedResponse<HrGeneratedDocument>>(`/hr-generated-documents${query}`)
            .then((response) => setDocuments(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [employeeIdFilter]);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <AppLayout>
            <Head title="HR Documents" />

            <PageHeader
                title="HR Documents"
                description="Letters and documents generated for employees from HR document templates."
                actions={
                    <PermissionGate permission="hr_generated_documents.generate">
                        <Link
                            href={employeeIdFilter ? `/hr-documents/create?employeeId=${employeeIdFilter}` : '/hr-documents/create'}
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            Generate document
                        </Link>
                    </PermissionGate>
                }
            />

            {error && <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>}

            {documents === null && !error && <LoadingState label="Loading HR documents…" />}

            {documents !== null && documents.length === 0 && (
                <EmptyState
                    title="No HR documents yet"
                    description="Documents generated for this tenant will appear here."
                />
            )}

            {documents !== null && documents.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Employee</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Generated</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {documents.map((document) => (
                                <tr key={document.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link href={`/hr-documents/${document.id}`} className="text-indigo-600 hover:text-indigo-500">
                                            {document.title}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-700">{document.employee?.full_name ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {HR_DOCUMENT_TYPE_LABELS[document.document_type]}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[document.status]}>{document.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {document.generated_at?.slice(0, 10) ?? '—'}
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
