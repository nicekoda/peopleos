import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { HR_DOCUMENT_ALLOWED_PLACEHOLDERS, HrDocumentTemplateVersion } from '@/types/hrDocument';
import { PageProps } from '@/types';

interface CreateProps extends PageProps {
    hrDocumentTemplateId: string;
}

/**
 * Checkpoint 36 — creates a new draft version; publishing is a separate,
 * dedicated action from the template's Edit page (Versions card), not
 * done here. content_template rendered as a plain textarea only — never
 * previewed via dangerouslySetInnerHTML, same rule as the template
 * Create form.
 */
export default function SettingsHrDocumentTemplateVersionCreate() {
    const { hrDocumentTemplateId } = usePage<CreateProps>().props;

    const [contentTemplate, setContentTemplate] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        api.post<{ data: HrDocumentTemplateVersion }>(`/hr-document-templates/${hrDocumentTemplateId}/versions`, {
            content_template: contentTemplate,
        })
            .then(() => {
                router.visit(`/settings/hr-document-templates/${hrDocumentTemplateId}/edit`);
            })
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

    return (
        <AppLayout>
            <Head title="Create template version" />

            <PageHeader
                title="Create template version"
                description="New versions start as drafts — publish from the template's Versions list when ready."
                actions={
                    <Link
                        href={`/settings/hr-document-templates/${hrDocumentTemplateId}/edit`}
                        className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        Back to template
                    </Link>
                }
            />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <label htmlFor="content_template" className="block text-sm font-medium text-slate-700">
                        Content
                    </label>
                    <textarea
                        id="content_template"
                        required
                        rows={14}
                        value={contentTemplate}
                        onChange={(e) => setContentTemplate(e.target.value)}
                        className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 font-mono text-xs text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                        placeholder="e.g. Dear {{employee.name}}, this letter confirms your employment as {{employee.position}}..."
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

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create draft version'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
