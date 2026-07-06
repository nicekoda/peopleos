import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { downloadHrGeneratedDocumentPdf } from '@/lib/download';
import { HR_DOCUMENT_TYPE_LABELS, HR_GENERATED_DOCUMENT_STATUS_TONE, HrGeneratedDocument } from '@/types/hrDocument';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    hrGeneratedDocumentId: string;
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
 * rendered_content is rendered as plain text only — never
 * `dangerouslySetInnerHTML`. React already escapes text children, so
 * `{content}` here cannot execute markup even if the rendered letter
 * happened to contain HTML-looking text. Same rule as Policies/Show.tsx.
 * rejection_reason (Checkpoint 37) follows the identical rule below.
 */
export default function HrDocumentShow() {
    const { hrGeneratedDocumentId } = usePage<ShowProps>().props;

    const [document, setDocument] = useState<HrGeneratedDocument | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const [titleInput, setTitleInput] = useState('');
    const [savingTitle, setSavingTitle] = useState(false);
    const [titleError, setTitleError] = useState<string | null>(null);
    const [titleSuccess, setTitleSuccess] = useState<string | null>(null);

    const [archiving, setArchiving] = useState(false);
    const [archiveError, setArchiveError] = useState<string | null>(null);

    const [downloadingPdf, setDownloadingPdf] = useState(false);
    const [downloadError, setDownloadError] = useState<string | null>(null);

    const [submitting, setSubmitting] = useState(false);
    const [workflowError, setWorkflowError] = useState<string | null>(null);
    const [approving, setApproving] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [rejectionReasonInput, setRejectionReasonInput] = useState('');
    const [showRejectForm, setShowRejectForm] = useState(false);

    const load = () => {
        api.get<{ data: HrGeneratedDocument }>(`/hr-generated-documents/${hrGeneratedDocumentId}`)
            .then((response) => {
                setDocument(response.data.data);
                setTitleInput(response.data.data.title);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hrGeneratedDocumentId]);

    const handleSaveTitle: FormEventHandler = (e) => {
        e.preventDefault();
        setSavingTitle(true);
        setTitleError(null);
        setTitleSuccess(null);

        api.patch(`/hr-generated-documents/${hrGeneratedDocumentId}`, { title: titleInput })
            .then(() => {
                setTitleSuccess('Title updated.');
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setTitleError(apiError.errors?.title?.[0] ?? apiError.message);
                }
            })
            .finally(() => setSavingTitle(false));
    };

    const handleDownloadPdf = async () => {
        if (!document) return;

        setDownloadingPdf(true);
        setDownloadError(null);

        const apiError = await downloadHrGeneratedDocumentPdf(hrGeneratedDocumentId, `${document.title}.pdf`);
        if (apiError && !redirectIfUnauthenticated(apiError)) {
            setDownloadError(apiError.message);
        }

        setDownloadingPdf(false);
    };

    const handleArchive = () => {
        if (!window.confirm('Archive this document? It will no longer appear as an active HR document.')) {
            return;
        }

        setArchiving(true);
        setArchiveError(null);

        api.delete(`/hr-generated-documents/${hrGeneratedDocumentId}`)
            .then(() => router.visit('/hr-documents'))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setArchiveError(apiError.message);
                }
            })
            .finally(() => setArchiving(false));
    };

    const handleSubmit = () => {
        setSubmitting(true);
        setWorkflowError(null);

        api.post(`/hr-generated-documents/${hrGeneratedDocumentId}/submit`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setWorkflowError(apiError.message);
                }
            })
            .finally(() => setSubmitting(false));
    };

    const handleApprove = () => {
        if (!window.confirm('Approve this document? It cannot be edited once approved.')) {
            return;
        }

        setApproving(true);
        setWorkflowError(null);

        api.post(`/hr-generated-documents/${hrGeneratedDocumentId}/approve`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setWorkflowError(apiError.message);
                }
            })
            .finally(() => setApproving(false));
    };

    const handleReject: FormEventHandler = (e) => {
        e.preventDefault();
        setRejecting(true);
        setWorkflowError(null);

        api.post(`/hr-generated-documents/${hrGeneratedDocumentId}/reject`, { rejection_reason: rejectionReasonInput })
            .then(() => {
                setShowRejectForm(false);
                setRejectionReasonInput('');
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setWorkflowError(apiError.errors?.rejection_reason?.[0] ?? apiError.message);
                }
            })
            .finally(() => setRejecting(false));
    };

    if (error) {
        return (
            <AppLayout>
                <Head title="HR Document" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!document) {
        return (
            <AppLayout>
                <Head title="HR Document" />
                <LoadingState label="Loading HR document…" />
            </AppLayout>
        );
    }

    const titleEditable = document.status === 'draft' || document.status === 'rejected';
    const canSubmit = document.status === 'draft' || document.status === 'rejected';
    const canReview = document.status === 'pending_approval';

    return (
        <AppLayout>
            <Head title={document.title} />

            <PageHeader
                title={document.title}
                description={
                    <>
                        {document.employee?.full_name ?? 'Unknown employee'} · {HR_DOCUMENT_TYPE_LABELS[document.document_type]} ·{' '}
                        <Badge tone={HR_GENERATED_DOCUMENT_STATUS_TONE[document.status]}>{document.status.replace('_', ' ')}</Badge>
                    </>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="secondary" disabled={downloadingPdf} onClick={handleDownloadPdf}>
                            {downloadingPdf ? 'Downloading…' : 'Download PDF'}
                        </Button>
                        <Link href="/hr-documents" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to HR Documents
                        </Link>
                    </div>
                }
            />

            {downloadError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{downloadError}</div>
            )}
            {workflowError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{workflowError}</div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Employee" value={document.employee?.full_name} />
                        <Field label="Document type" value={HR_DOCUMENT_TYPE_LABELS[document.document_type]} />
                        <Field label="Generated at" value={document.generated_at?.slice(0, 10)} />
                    </dl>
                </Card>

                {titleEditable ? (
                    <PermissionGate permission="hr_generated_documents.update">
                        <Card title="Title">
                            <form onSubmit={handleSaveTitle} className="flex flex-col gap-3">
                                {titleSuccess && (
                                    <p className="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{titleSuccess}</p>
                                )}
                                <InputField
                                    label="Title"
                                    name="title"
                                    required
                                    value={titleInput}
                                    onChange={(e) => setTitleInput(e.target.value)}
                                    error={titleError ?? undefined}
                                />
                                <div>
                                    <Button type="submit" variant="secondary" disabled={savingTitle}>
                                        {savingTitle ? 'Saving…' : 'Save title'}
                                    </Button>
                                </div>
                            </form>
                        </Card>
                    </PermissionGate>
                ) : (
                    <Card title="Approval">
                        <dl className="divide-y divide-slate-100">
                            <Field label="Submitted" value={document.submitted_at?.slice(0, 10)} />
                            <Field label="Approved" value={document.approved_at?.slice(0, 10)} />
                        </dl>
                        <p className="mt-2 text-xs text-slate-500">Title can no longer be edited in this status.</p>
                    </Card>
                )}
            </div>

            {document.rejection_reason && (
                <div className="mt-4">
                    <Card title="Rejection reason">
                        <dd className="whitespace-pre-wrap rounded-md bg-red-50 p-4 text-sm text-red-700">
                            {document.rejection_reason}
                        </dd>
                    </Card>
                </div>
            )}

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Rendered content">
                    <dd className="whitespace-pre-wrap rounded-md bg-slate-50 p-4 text-sm text-slate-900">
                        {document.rendered_content}
                    </dd>
                </Card>

                <Card title="Approval actions">
                    <div className="flex flex-col gap-3">
                        <PermissionGate permission="hr_generated_documents.submit">
                            {canSubmit && (
                                <Button type="button" disabled={submitting} onClick={handleSubmit}>
                                    {submitting ? 'Submitting…' : document.status === 'rejected' ? 'Resubmit for approval' : 'Submit for approval'}
                                </Button>
                            )}
                        </PermissionGate>

                        <PermissionGate permission="hr_generated_documents.approve">
                            {canReview && (
                                <Button type="button" disabled={approving} onClick={handleApprove}>
                                    {approving ? 'Approving…' : 'Approve'}
                                </Button>
                            )}
                        </PermissionGate>

                        <PermissionGate permission="hr_generated_documents.reject">
                            {canReview && !showRejectForm && (
                                <Button type="button" variant="secondary" onClick={() => setShowRejectForm(true)}>
                                    Reject
                                </Button>
                            )}
                            {canReview && showRejectForm && (
                                <form onSubmit={handleReject} className="flex flex-col gap-3">
                                    <div>
                                        <label htmlFor="rejection_reason" className="block text-sm font-medium text-slate-700">
                                            Rejection reason
                                        </label>
                                        <textarea
                                            id="rejection_reason"
                                            required
                                            rows={3}
                                            value={rejectionReasonInput}
                                            onChange={(e) => setRejectionReasonInput(e.target.value)}
                                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                                        />
                                    </div>
                                    <div className="flex gap-3">
                                        <Button type="submit" variant="secondary" disabled={rejecting}>
                                            {rejecting ? 'Rejecting…' : 'Confirm rejection'}
                                        </Button>
                                        <Button type="button" variant="secondary" onClick={() => setShowRejectForm(false)}>
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </PermissionGate>

                        {!canSubmit && !canReview && (
                            <p className="text-sm text-slate-500">No approval actions available for this document's current status.</p>
                        )}
                    </div>
                </Card>
            </div>

            <PermissionGate permission="hr_generated_documents.delete">
                <div className="mt-4">
                    <Card title="Danger zone">
                        {archiveError && (
                            <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{archiveError}</p>
                        )}
                        <Button type="button" variant="secondary" disabled={archiving} onClick={handleArchive}>
                            {archiving ? 'Archiving…' : 'Archive document'}
                        </Button>
                    </Card>
                </div>
            </PermissionGate>
        </AppLayout>
    );
}
