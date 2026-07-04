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
import { DocumentCategory, DocumentCategoryFormPayload } from '@/types/documentCategory';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    documentCategoryId: string;
}

/**
 * `status` is the one field this form adds beyond Create — editing an
 * existing category may need to deactivate it (hidden from new
 * uploads) without archiving it entirely. tenant_id/created_by/
 * updated_by/deleted_at/slug remain structurally absent (Refinement 2).
 */
export default function SettingsDocumentCategoryEdit() {
    const { documentCategoryId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<DocumentCategoryFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: DocumentCategory }>(`/document-categories/${documentCategoryId}`)
            .then((response) => {
                const category = response.data.data;
                setForm({
                    name: category.name,
                    description: category.description ?? '',
                    is_sensitive: category.is_sensitive,
                    is_required: category.is_required,
                    requires_expiry_date: category.requires_expiry_date,
                    status: category.status,
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [documentCategoryId]);

    const set = <K extends keyof DocumentCategoryFormPayload>(key: K, value: DocumentCategoryFormPayload[K]) => {
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

        api.patch<{ data: DocumentCategory }>(`/document-categories/${documentCategoryId}`, form)
            .then(() => setSuccessMessage('Category updated.'))
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
                <Head title="Edit document category" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit document category" />
                <LoadingState label="Loading category…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit document category" />

            <PageHeader title="Edit document category" />

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
                            label="Name"
                            name="name"
                            required
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={fieldError('name')}
                        />
                        <SelectField
                            label="Status"
                            name="status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value as DocumentCategoryFormPayload['status'])}
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
                                rows={3}
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('description')} />
                        </div>

                        <div className="sm:col-span-2 space-y-3">
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
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => router.visit('/settings/document-categories')}
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
