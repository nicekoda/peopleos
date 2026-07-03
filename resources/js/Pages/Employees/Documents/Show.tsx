import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { downloadEmployeeDocument } from '@/lib/download';
import { DocumentCategory, EmployeeDocument, PaginatedResponse } from '@/types/document';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    employeeId: string;
    documentId: string;
}

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

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Safe metadata only, per your explicit "do not show" list: storage_path,
 * storage_disk, and stored_filename are never requested from the API in
 * the first place (EmployeeDocumentResource never returns them — see
 * docs/security.md), so there's nothing here that could leak them even
 * by accident. No file content preview is built (Refinement 9) — this
 * page only ever links out to the authenticated download endpoint via
 * lib/download.ts.
 */
export default function EmployeeDocumentShow() {
    const { employeeId, documentId } = usePage<ShowProps>().props;

    const [document, setDocument] = useState<EmployeeDocument | null>(null);
    const [categories, setCategories] = useState<DocumentCategory[]>([]);
    const [error, setError] = useState<ApiError | null>(null);

    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const load = () => {
        setError(null);
        api.get<{ data: EmployeeDocument }>(`/employees/${employeeId}/documents/${documentId}`)
            .then((response) => setDocument(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    };

    useEffect(load, [employeeId, documentId]);

    useEffect(() => {
        api.get<PaginatedResponse<DocumentCategory>>('/document-categories')
            .then((response) => setCategories(response.data.data))
            .catch(() => setCategories([]));
    }, []);

    const categoryName = document?.document_category_id
        ? categories.find((category) => category.id === document.document_category_id)?.name ?? 'Category unavailable'
        : 'Uncategorised';

    const handleDownload = () => {
        if (!document) return;

        setProcessing(true);
        setActionError(null);

        downloadEmployeeDocument(employeeId, document.id, document.original_filename)
            .then((apiError) => {
                if (apiError && !redirectIfUnauthenticated(apiError)) {
                    setActionError(apiError.message);
                }
            })
            .finally(() => setProcessing(false));
    };

    /**
     * Refinement 6 — no optimistic navigation before the backend
     * confirms success; only redirect to the list once the DELETE call
     * has actually succeeded. A 403/404 surfaces as a safe inline
     * message and leaves the page exactly as it was.
     */
    const handleDelete = () => {
        if (!document) return;
        if (!window.confirm(`Delete "${document.title}"? This can be reversed by an administrator.`)) {
            return;
        }

        setProcessing(true);
        setActionError(null);

        api.delete(`/employees/${employeeId}/documents/${document.id}`)
            .then(() => router.visit(`/employees/${employeeId}/documents`))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setActionError(apiError.message);
                }
                setProcessing(false);
            });
    };

    if (error) {
        return (
            <AppLayout>
                <Head title="Document" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!document) {
        return (
            <AppLayout>
                <Head title="Document" />
                <LoadingState label="Loading document…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={document.title} />

            <PageHeader
                title={document.title}
                description={
                    <>
                        {categoryName}
                        {document.is_sensitive && (
                            <span className="ml-2">
                                <Badge tone="warning">Sensitive</Badge>
                            </span>
                        )}
                    </>
                }
                actions={
                    <Link
                        href={`/employees/${employeeId}/documents`}
                        className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        Back to documents
                    </Link>
                }
            />

            {actionError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{actionError}</div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Metadata">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Description" value={document.description} />
                        <Field label="Original filename" value={document.original_filename} />
                        <Field label="File type" value={document.mime_type} />
                        <Field label="File size" value={formatFileSize(document.file_size)} />
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Status</dt>
                            <dd>
                                <Badge tone={statusTone[document.status]}>{document.status}</Badge>
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card title="Dates">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Issue date" value={document.issue_date} />
                        <Field label="Expiry date" value={document.expiry_date} />
                        <Field label="Uploaded" value={document.created_at.slice(0, 10)} />
                    </dl>
                </Card>
            </div>

            <div className="mt-6 flex justify-end gap-3">
                <PermissionGate permission="documents.download">
                    <Button type="button" variant="secondary" disabled={processing} onClick={handleDownload}>
                        {processing ? 'Working…' : 'Download'}
                    </Button>
                </PermissionGate>
                <PermissionGate permission="documents.delete">
                    <Button type="button" variant="danger" disabled={processing} onClick={handleDelete}>
                        {processing ? 'Working…' : 'Delete'}
                    </Button>
                </PermissionGate>
            </div>
        </AppLayout>
    );
}
