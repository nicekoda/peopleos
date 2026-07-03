import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Policy, PolicyFormPayload } from '@/types/policy';

/**
 * Payload is built field-by-field from PolicyFormPayload, never by
 * spreading a broader object — tenant_id/status/current_version_id/
 * created_by/updated_by/published_by are never fields on this form.
 * `owner_user_id` is omitted entirely: the backend validates it safely,
 * but there is no /api/v1/users listing endpoint yet to build a safe
 * picker on, per your explicit instruction. `slug` is also omitted —
 * StorePolicyRequest auto-derives it from title when not supplied.
 */
export default function PolicyCreate() {
    const [form, setForm] = useState<PolicyFormPayload>({
        title: '',
        code: '',
        description: '',
        category: '',
        effective_date: '',
        review_date: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const set = <K extends keyof PolicyFormPayload>(key: K, value: PolicyFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));

        api.post<{ data: Policy }>('/policies', payload)
            .then((response) => {
                router.visit(`/policies/${response.data.data.id}`);
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
            <Head title="Create policy" />

            <PageHeader title="Create policy" description="Starts as a draft — publish a version afterwards." />

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
                        <InputField
                            label="Code"
                            name="code"
                            value={form.code}
                            onChange={(e) => set('code', e.target.value)}
                            error={fieldError('code')}
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

                        <InputField
                            label="Category"
                            name="category"
                            value={form.category}
                            onChange={(e) => set('category', e.target.value)}
                            error={fieldError('category')}
                        />
                        <div />

                        <InputField
                            label="Effective date"
                            name="effective_date"
                            type="date"
                            value={form.effective_date}
                            onChange={(e) => set('effective_date', e.target.value)}
                            error={fieldError('effective_date')}
                        />
                        <InputField
                            label="Review date"
                            name="review_date"
                            type="date"
                            value={form.review_date}
                            onChange={(e) => set('review_date', e.target.value)}
                            error={fieldError('review_date')}
                        />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create policy'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
