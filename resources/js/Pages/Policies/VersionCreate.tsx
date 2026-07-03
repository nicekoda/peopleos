import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PolicyVersion, PolicyVersionFormPayload } from '@/types/policy';
import { PageProps } from '@/types';

interface VersionCreateProps extends PageProps {
    policyId: string;
}

/**
 * `version_number` is never a field here — the backend computes it
 * automatically (max(version_number) + 1). `employee_document_id` is
 * also omitted — no safe document picker exists yet for policy/general
 * documents (see docs/security.md); this form only creates content-based
 * versions. A new version always starts as a draft — publishing is the
 * separate action on the policy detail page.
 */
export default function PolicyVersionCreate() {
    const { policyId } = usePage<VersionCreateProps>().props;

    const [form, setForm] = useState<PolicyVersionFormPayload>({
        title: '',
        summary: '',
        content: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const set = <K extends keyof PolicyVersionFormPayload>(key: K, value: PolicyVersionFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));

        api.post<{ data: PolicyVersion }>(`/policies/${policyId}/versions`, payload)
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

    return (
        <AppLayout>
            <Head title="Create policy version" />

            <PageHeader title="Create version" description="Creates a new draft version — publish it from the policy page afterwards." />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4">
                        <div>
                            <label htmlFor="title" className="block text-sm font-medium text-slate-700">
                                Title <span className="text-red-500">*</span>
                            </label>
                            <input
                                id="title"
                                name="title"
                                required
                                value={form.title}
                                onChange={(e) => set('title', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('title')} />
                        </div>

                        <div>
                            <label htmlFor="summary" className="block text-sm font-medium text-slate-700">
                                Summary
                            </label>
                            <textarea
                                id="summary"
                                rows={2}
                                value={form.summary}
                                onChange={(e) => set('summary', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('summary')} />
                        </div>

                        <div>
                            <label htmlFor="content" className="block text-sm font-medium text-slate-700">
                                Content
                            </label>
                            <textarea
                                id="content"
                                rows={10}
                                value={form.content}
                                onChange={(e) => set('content', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 font-mono text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600"
                            />
                            <p className="mt-1 text-xs text-slate-500">Plain text only — rendered as-is, never as HTML.</p>
                            <ErrorMessage message={fieldError('content')} />
                        </div>
                    </div>

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
