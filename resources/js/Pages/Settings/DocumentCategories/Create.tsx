import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { DocumentCategory, DocumentCategoryFormPayload } from '@/types/documentCategory';

/**
 * Payload is built field-by-field from DocumentCategoryFormPayload,
 * never by spreading a broader object — tenant_id/created_by/
 * updated_by/deleted_at/slug are never fields on this form at all
 * (Refinement 2). `applies_to` is also omitted — the backend defaults
 * it to `employee`, matching every category in real use today.
 * `status` isn't sent either — a new category always starts `active`
 * via the backend's own default.
 */
export default function SettingsDocumentCategoryCreate() {
    const [form, setForm] = useState<Omit<DocumentCategoryFormPayload, 'status'>>({
        name: '',
        description: '',
        is_sensitive: false,
        is_required: false,
        requires_expiry_date: false,
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
            name: form.name,
            ...(form.description ? { description: form.description } : {}),
            is_sensitive: form.is_sensitive,
            is_required: form.is_required,
            requires_expiry_date: form.requires_expiry_date,
        };

        api.post<{ data: DocumentCategory }>('/document-categories', payload)
            .then((response) => {
                router.visit(`/settings/document-categories/${response.data.data.id}/edit`);
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
            <Head title="Create document category" />

            <PageHeader title="Create document category" description="New categories start active." />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4">
                        <InputField
                            label="Name"
                            name="name"
                            required
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={fieldError('name')}
                        />

                        <div>
                            <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                Description
                            </label>
                            <textarea
                                id="description"
                                rows={3}
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('description')} />
                        </div>

                        <div className="space-y-3">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_sensitive}
                                    onChange={(e) => set('is_sensitive', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                Sensitive — documents in this category are hidden from users without <code>documents.view_sensitive</code>
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_required}
                                    onChange={(e) => set('is_required', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                Required
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.requires_expiry_date}
                                    onChange={(e) => set('requires_expiry_date', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                Requires an expiry date on upload
                            </label>
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create category'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
