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
import { LifecycleTaskTemplate, LifecycleTaskTemplateFormPayload } from '@/types/lifecycleTaskTemplate';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    lifecycleTaskTemplateId: string;
}

export default function SettingsLifecycleTaskTemplateEdit() {
    const { lifecycleTaskTemplateId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<LifecycleTaskTemplateFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: LifecycleTaskTemplate }>(`/lifecycle-task-templates/${lifecycleTaskTemplateId}`)
            .then((response) => {
                const template = response.data.data;
                setForm({
                    type: template.type,
                    title: template.title,
                    description: template.description ?? '',
                    due_in_days: template.due_in_days === null ? '' : String(template.due_in_days),
                    sort_order: String(template.sort_order),
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [lifecycleTaskTemplateId]);

    const set = <K extends keyof LifecycleTaskTemplateFormPayload>(key: K, value: LifecycleTaskTemplateFormPayload[K]) => {
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

        const payload = {
            type: form.type,
            title: form.title,
            description: form.description === '' ? null : form.description,
            due_in_days: form.due_in_days === '' ? null : Number(form.due_in_days),
            sort_order: form.sort_order === '' ? 0 : Number(form.sort_order),
        };

        api.patch<{ data: LifecycleTaskTemplate }>(`/lifecycle-task-templates/${lifecycleTaskTemplateId}`, payload)
            .then(() => setSuccessMessage('Task template updated.'))
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
                <Head title="Edit task template" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit task template" />
                <LoadingState label="Loading task template…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit task template" />

            <PageHeader title="Edit task template" />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Process type"
                            name="type"
                            required
                            value={form.type}
                            onChange={(e) => set('type', e.target.value as LifecycleTaskTemplateFormPayload['type'])}
                            error={fieldError('type')}
                        >
                            <option value="onboarding">Onboarding</option>
                            <option value="offboarding">Offboarding</option>
                        </SelectField>

                        <InputField
                            label="Title"
                            name="title"
                            required
                            value={form.title}
                            onChange={(e) => set('title', e.target.value)}
                            error={fieldError('title')}
                        />

                        <InputField
                            label="Due (days after the process starts)"
                            name="due_in_days"
                            type="number"
                            min={0}
                            max={365}
                            value={form.due_in_days}
                            onChange={(e) => set('due_in_days', e.target.value)}
                            error={fieldError('due_in_days')}
                        />

                        <InputField
                            label="Sort order"
                            name="sort_order"
                            type="number"
                            min={0}
                            max={1000}
                            value={form.sort_order}
                            onChange={(e) => set('sort_order', e.target.value)}
                            error={fieldError('sort_order')}
                        />

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
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" onClick={() => router.visit('/settings/lifecycle-task-templates')}>
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
