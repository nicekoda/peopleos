import { Head, Link, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { HR_DOCUMENT_ALLOWED_PLACEHOLDERS, HrDocumentTemplateVersion, HrDocumentTemplateVersionStatus } from '@/types/hrDocument';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    hrDocumentTemplateVersionId: string;
}

const versionStatusTone: Record<HrDocumentTemplateVersionStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    published: 'success',
    archived: 'neutral',
};

/**
 * Checkpoint 36 — editable only while the version is draft
 * (UpdateHrDocumentTemplateVersionRequest rejects the PATCH otherwise).
 * A published/archived version still loads here so it can at least be
 * read, with the form disabled and an explanatory note, rather than
 * 404ing on a status the page doesn't like.
 */
export default function SettingsHrDocumentTemplateVersionEdit() {
    const { hrDocumentTemplateVersionId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [version, setVersion] = useState<HrDocumentTemplateVersion | null>(null);
    const [contentTemplate, setContentTemplate] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: HrDocumentTemplateVersion }>(`/hr-document-template-versions/${hrDocumentTemplateVersionId}`)
            .then((response) => {
                setVersion(response.data.data);
                setContentTemplate(response.data.data.content_template);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [hrDocumentTemplateVersionId]);

    const fieldError = (name: string) => errors[name]?.[0];
    const isDraft = version?.status === 'draft';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!version) return;

        setSubmitting(true);
        setErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        api.patch<{ data: HrDocumentTemplateVersion }>(`/hr-document-template-versions/${hrDocumentTemplateVersionId}`, {
            content_template: contentTemplate,
        })
            .then(() => setSuccessMessage('Draft version updated.'))
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (apiError.errors) {
                    setErrors(apiError.errors);
                }
                setGeneralError(apiError.message);
            })
            .finally(() => setSubmitting(false));
    };

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Edit template version" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!version) {
        return (
            <AppLayout>
                <Head title="Edit template version" />
                <LoadingState label="Loading version…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={`Edit version ${version.version_number}`} />

            <PageHeader
                title={`Edit version ${version.version_number}`}
                description={<Badge tone={versionStatusTone[version.status]}>{version.status}</Badge>}
                actions={
                    <Link
                        href={`/settings/hr-document-templates/${version.hr_document_template_id}/edit`}
                        className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        Back to template
                    </Link>
                }
            />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}
            {!isDraft && (
                <div className="mb-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    This version is {version.status}, not draft — it can be viewed here but no longer edited.
                </div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <label htmlFor="content_template" className="block text-sm font-medium text-slate-700">
                        Content
                    </label>
                    <textarea
                        id="content_template"
                        required
                        disabled={!isDraft}
                        rows={14}
                        value={contentTemplate}
                        onChange={(e) => setContentTemplate(e.target.value)}
                        className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 font-mono text-xs text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 disabled:bg-slate-50 disabled:text-slate-500"
                    />
                    <ErrorMessage message={fieldError('content_template')} />
                    <p className="mt-2 text-xs text-slate-500">
                        Plain text only — no HTML or code. Available placeholders:{' '}
                        {HR_DOCUMENT_ALLOWED_PLACEHOLDERS.map((token, i) => (
                            <span key={token}>
                                <code className="rounded bg-slate-100 px-1 py-0.5">{token}</code>
                                {i < HR_DOCUMENT_ALLOWED_PLACEHOLDERS.length - 1 ? ', ' : ''}
                            </span>
                        ))}
                        . Any other <code>{'{{...}}'}</code> text is left exactly as written.
                    </p>

                    {isDraft && (
                        <div className="mt-6 flex justify-end gap-3">
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Saving…' : 'Save changes'}
                            </Button>
                        </div>
                    )}
                </Card>
            </form>
        </AppLayout>
    );
}
