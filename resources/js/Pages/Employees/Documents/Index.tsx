import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { downloadEmployeeDocument } from '@/lib/download';
import { formatEmployeeRef } from '@/lib/format';
import { DocumentCategory, EmployeeDocument, PaginatedResponse } from '@/types/document';
import { Employee } from '@/types/employee';
import { PageProps } from '@/types';

const statusTone: Record<EmployeeDocument['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    archived: 'neutral',
    rejected: 'danger',
};

function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

interface IndexProps extends PageProps {
    employeeId: string;
}

/**
 * Document data is fetched client-side from the existing, already-tested
 * /api/v1/employees/{employee}/documents endpoint (Checkpoint 8) — see
 * EmployeeDocumentUiController and docs/architecture.md. Categories are
 * fetched separately (their own loading/error state, same pattern as
 * Leave's Index.tsx) purely to resolve document_category_id to a
 * readable name; a failure there doesn't block the document list itself
 * from rendering.
 *
 * storage_path/storage_disk/stored_filename are never requested or
 * rendered anywhere on this page — EmployeeDocumentResource never
 * returns them in the first place (see docs/security.md).
 */
export default function EmployeeDocumentsIndex() {
    const { employeeId } = usePage<IndexProps>().props;
    const viewerEmployeeId = usePage<IndexProps>().props.auth.user?.employee_id ?? null;

    const [employee, setEmployee] = useState<Employee | null>(null);
    const [employeeRefFailed, setEmployeeRefFailed] = useState(false);

    const [documents, setDocuments] = useState<EmployeeDocument[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const [categories, setCategories] = useState<DocumentCategory[]>([]);

    const [busyId, setBusyId] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    const loadDocuments = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<EmployeeDocument>>(`/employees/${employeeId}/documents`)
            .then((response) => setDocuments(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [employeeId]);

    useEffect(() => {
        loadDocuments();
    }, [loadDocuments]);

    useEffect(() => {
        api.get<{ data: Employee }>(`/employees/${employeeId}`)
            .then((response) => setEmployee(response.data.data))
            .catch(() => setEmployeeRefFailed(true));
    }, [employeeId]);

    useEffect(() => {
        api.get<PaginatedResponse<DocumentCategory>>('/document-categories')
            .then((response) => setCategories(response.data.data))
            .catch(() => setCategories([]));
    }, []);

    const categoryName = (categoryId: string | null) => {
        if (!categoryId) {
            return 'Uncategorised';
        }

        return categories.find((category) => category.id === categoryId)?.name ?? 'Uncategorised';
    };

    const employeeRef = employee ? employee.full_name : employeeRefFailed ? formatEmployeeRef(employeeId, viewerEmployeeId) : null;

    /**
     * Refinement 6 — no optimistic removal. The row disappears only after
     * a full refetch confirms the backend actually deleted it; a 403/404
     * surfaces as a safe inline message, never a raw response body.
     */
    const handleDelete = (document: EmployeeDocument) => {
        if (!window.confirm(`Delete "${document.title}"? This can be reversed by an administrator.`)) {
            return;
        }

        setBusyId(document.id);
        setActionError(null);

        api.delete(`/employees/${employeeId}/documents/${document.id}`)
            .then(() => loadDocuments())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setActionError(apiError.message);
                }
            })
            .finally(() => setBusyId(null));
    };

    const handleDownload = (document: EmployeeDocument) => {
        setBusyId(document.id);
        setActionError(null);

        downloadEmployeeDocument(employeeId, document.id, document.original_filename)
            .then((apiError) => {
                if (apiError && !redirectIfUnauthenticated(apiError)) {
                    setActionError(apiError.message);
                }
            })
            .finally(() => setBusyId(null));
    };

    return (
        <AppLayout>
            <Head title="Employee documents" />

            <PageHeader
                title="Documents"
                description={employeeRef ? `Documents for ${employeeRef}` : 'Loading employee reference…'}
                actions={
                    <div className="flex items-center gap-3">
                        <Link href={`/employees/${employeeId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to employee
                        </Link>
                        <PermissionGate permission="documents.upload">
                            <Link
                                href={`/employees/${employeeId}/documents/upload`}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Upload document
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {actionError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{actionError}</div>
            )}

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {documents === null && !error && <LoadingState label="Loading documents…" />}

            {documents !== null && documents.length === 0 && (
                <EmptyState
                    title="No documents yet"
                    description="Documents uploaded for this employee will appear here."
                />
            )}

            {documents !== null && documents.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Category</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">File</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Size</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Issued</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Expires</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Uploaded</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {documents.map((document) => (
                                <tr key={document.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link
                                            href={`/employees/${employeeId}/documents/${document.id}`}
                                            className="hover:text-indigo-600"
                                        >
                                            {document.title}
                                        </Link>
                                        {document.is_sensitive && (
                                            <span className="ml-2">
                                                <Badge tone="warning">Sensitive</Badge>
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {categoryName(document.document_category_id)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[document.status]}>{document.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {document.original_filename} ({document.file_extension})
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatFileSize(document.file_size)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{document.issue_date ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{document.expiry_date ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{document.created_at.slice(0, 10)}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <Link
                                                href={`/employees/${employeeId}/documents/${document.id}`}
                                                className="text-indigo-600 hover:text-indigo-500"
                                            >
                                                View
                                            </Link>
                                            <PermissionGate permission="documents.download">
                                                <button
                                                    type="button"
                                                    onClick={() => handleDownload(document)}
                                                    disabled={busyId === document.id}
                                                    className="text-indigo-600 hover:text-indigo-500 disabled:opacity-50"
                                                >
                                                    {busyId === document.id ? 'Working…' : 'Download'}
                                                </button>
                                            </PermissionGate>
                                            <PermissionGate permission="documents.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(document)}
                                                    disabled={busyId === document.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {busyId === document.id ? 'Working…' : 'Delete'}
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
