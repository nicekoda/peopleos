import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Policy, PolicyFormPayload } from '@/types/policy';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    policyId: string;
}

/**
 * `status` is deliberately never a field on this form, even though
 * UpdatePolicyRequest technically accepts it — status changes go through
 * the dedicated publish flow (Show.tsx) instead, which enforces the
 * real invariants (a version must exist, have content, etc.) that a
 * bare status field on this generic form would bypass. See
 * docs/security.md for the full reasoning.
 */
export default function PolicyEdit() {
    const { policyId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<PolicyFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: Policy }>(`/policies/${policyId}`)
            .then((response) => {
                const policy = response.data.data;
                setForm({
                    title: policy.title,
                    code: policy.code ?? '',
                    description: policy.description ?? '',
                    category: policy.category ?? '',
                    effective_date: policy.effective_date ?? '',
                    review_date: policy.review_date ?? '',
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [policyId]);

    const set = <K extends keyof PolicyFormPayload>(key: K, value: PolicyFormPayload[K]) => {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!form) return;

        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        api.patch<{ data: Policy }>(`/policies/${policyId}`, form)
            .then(() => {
                router.visit(`/policies/${policyId}`);
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

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Edit policy" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit policy" />
                <LoadingState label="Loading policy…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit policy" />

            <PageHeader title="Edit policy" />

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
                            {submitting ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
