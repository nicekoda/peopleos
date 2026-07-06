import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import {
    HR_DOCUMENT_ALLOWED_PLACEHOLDERS,
    HR_DOCUMENT_TYPE_LABELS,
    HrDocumentTemplate,
    HrDocumentTemplateFormPayload,
} from '@/types/hrDocument';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    hrDocumentTemplateId: string;
}

/**
 * `status` is the one field this form adds beyond Create — editing an
 * existing template may need to deactivate it (hidden from new
 * generation) without archiving it entirely. tenant_id/created_by/
 * updated_by/deleted_at/slug remain structurally absent, same convention
 * as Document Categories' Edit page.
 */
export default function SettingsHrDocumentTemplateEdit() {
    const { hrDocumentTemplateId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<HrDocumentTemplateFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: HrDocumentTemplate }>(`/hr-document-templates/${hrDocumentTemplateId}`)
            .then((response) => {
                const template = response.data.data;
                setForm({
                    title: template.title,
                    description: template.description ?? '',
                    document_type: template.document_type,
                    content_template: template.content_template,
                    status: template.status,
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [hrDocumentTemplateId]);

    const set = <K extends keyof HrDocumentTemplateFormPayload>(key: K, value: HrDocumentTemplateFormPayload[K]) => {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!form) return;

        setSubmitting(true);
        setErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        api.patch<{ data: HrDocumentTemplate }>(`/hr-document-templates/${hrDocumentTemplateId}`, form)
            .then(() => setSuccessMessage('Template updated.'))
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
                <Head title="Edit HR document template" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit HR document template" />
                <LoadingState label="Loading template…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit HR document template" />

            <PageHeader title="Edit HR document template" />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
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
                        <SelectField
                            label="Status"
                            name="status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value as HrDocumentTemplateFormPayload['status'])}
                            error={fieldError('status')}
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
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
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => router.visit('/settings/hr-document-templates')}
                        >
                            Back to list
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
