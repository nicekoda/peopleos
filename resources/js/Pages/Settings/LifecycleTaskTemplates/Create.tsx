import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LifecycleTaskTemplate, LifecycleTaskTemplateFormPayload } from '@/types/lifecycleTaskTemplate';

/**
 * Checkpoint 42 — type/title/description/due_in_days/sort_order.
 * due_in_days left blank means the generated task gets no due date at
 * all, not "due today" — the backend only computes a due date when
 * due_in_days is explicitly set (see LifecycleTaskTemplateApplier).
 */
export default function SettingsLifecycleTaskTemplateCreate() {
    const [form, setForm] = useState<LifecycleTaskTemplateFormPayload>({
        type: '',
        title: '',
        description: '',
        due_in_days: '',
        sort_order: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const set = <K extends keyof LifecycleTaskTemplateFormPayload>(key: K, value: LifecycleTaskTemplateFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            type: form.type,
            title: form.title,
            ...(form.description ? { description: form.description } : {}),
            ...(form.due_in_days !== '' ? { due_in_days: Number(form.due_in_days) } : {}),
            ...(form.sort_order !== '' ? { sort_order: Number(form.sort_order) } : {}),
        };

        api.post<{ data: LifecycleTaskTemplate }>('/lifecycle-task-templates', payload)
            .then(() => {
                router.visit('/settings/lifecycle-task-templates');
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
            <Head title="Add task template" />

            <PageHeader
                title="Add task template"
                description="This will be applied automatically to every newly started process of the selected type."
                actions={
                    <Link href="/settings/lifecycle-task-templates" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Task Templates
                    </Link>
                }
            />

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
                            <option value="" disabled>
                                Select a type…
                            </option>
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
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create template'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
