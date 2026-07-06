import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import {
    HR_DOCUMENT_ALLOWED_PLACEHOLDERS,
    HR_DOCUMENT_TYPE_LABELS,
    HrDocumentTemplate,
    HrDocumentTemplateFormPayload,
} from '@/types/hrDocument';

/**
 * Payload is built field-by-field, never by spreading a broader object —
 * tenant_id/created_by/updated_by/deleted_at/slug/status are never
 * fields on this form at all (slug is auto-generated from title
 * server-side; a new template always starts `active`). content_template
 * is rendered here as a plain textarea only — never previewed via
 * dangerouslySetInnerHTML — matching the "content is plain text, not
 * HTML" rule used for Policy content.
 */
export default function SettingsHrDocumentTemplateCreate() {
    const [form, setForm] = useState<Omit<HrDocumentTemplateFormPayload, 'status'>>({
        title: '',
        description: '',
        document_type: '',
        content_template: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            title: form.title,
            ...(form.description ? { description: form.description } : {}),
            document_type: form.document_type,
            content_template: form.content_template,
        };

        api.post<{ data: HrDocumentTemplate }>('/hr-document-templates', payload)
            .then((response) => {
                router.visit(`/settings/hr-document-templates/${response.data.data.id}/edit`);
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
            <Head title="Create HR document template" />

            <PageHeader title="Create HR document template" description="New templates start active." />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <InputField
                            label="Title"
                            name="title"
                            required
                            value={form.title}
                            onChange={(e) => set('title', e.target.value)}
                            error={fieldError('title')}
                        />
                        <SelectField
                            label="Document type"
                            name="document_type"
                            required
                            value={form.document_type}
                            onChange={(e) => set('document_type', e.target.value as HrDocumentTemplateFormPayload['document_type'])}
                            error={fieldError('document_type')}
                        >
                            <option value="">— Select a type —</option>
                            {Object.entries(HR_DOCUMENT_TYPE_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </SelectField>

                        <div className="sm:col-span-2">
                            <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                Description
                            </label>
                            <textarea
                                id="description"
                                rows={2}
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('description')} />
                        </div>

                        <div className="sm:col-span-2">
                            <label htmlFor="content_template" className="block text-sm font-medium text-slate-700">
                                Content
                            </label>
                            <textarea
                                id="content_template"
                                required
                                rows={12}
                                value={form.content_template}
                                onChange={(e) => set('content_template', e.target.value)}
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
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create template'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
