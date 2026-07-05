import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LifecycleProcess, LifecycleProcessEditPayload, LifecycleProcessStatus } from '@/types/lifecycle';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    processId: string;
}

/**
 * Mirrors LifecycleProcessStatus::allowedNextStates() (backend, single
 * source of truth) purely so the dropdown doesn't offer an obviously
 * illegal choice — the backend re-validates every transition regardless
 * of what this UI shows.
 */
const allowedNextStates: Record<LifecycleProcessStatus, LifecycleProcessStatus[]> = {
    draft: ['draft', 'in_progress', 'cancelled'],
    in_progress: ['in_progress', 'completed', 'cancelled'],
    completed: ['completed'],
    cancelled: ['cancelled'],
};

export default function LifecycleEdit() {
    const { processId } = usePage<EditProps>().props;

    const [process, setProcess] = useState<LifecycleProcess | null>(null);
    const [form, setForm] = useState<LifecycleProcessEditPayload | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: LifecycleProcess }>(`/lifecycle-processes/${processId}`)
            .then((response) => {
                const data = response.data.data;
                setProcess(data);
                setForm({
                    status: data.status,
                    started_at: data.started_at?.slice(0, 10) ?? '',
                    due_date: data.due_date ?? '',
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [processId]);

    const set = <K extends keyof LifecycleProcessEditPayload>(key: K, value: LifecycleProcessEditPayload[K]) => {
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
            ...(form.status ? { status: form.status } : {}),
            started_at: form.started_at === '' ? null : form.started_at,
            due_date: form.due_date === '' ? null : form.due_date,
        };

        api.patch<{ data: LifecycleProcess }>(`/lifecycle-processes/${processId}`, payload)
            .then((response) => {
                setSuccessMessage('Process updated.');
                setProcess(response.data.data);
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
                <Head title="Edit lifecycle process" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!process || !form) {
        return (
            <AppLayout>
                <Head title="Edit lifecycle process" />
                <LoadingState label="Loading lifecycle process…" />
            </AppLayout>
        );
    }

    const isTerminal = process.status === 'completed' || process.status === 'cancelled';

    return (
        <AppLayout>
            <Head title="Edit lifecycle process" />

            <PageHeader title="Edit lifecycle process" description={process.employee?.full_name ?? undefined} />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            {isTerminal ? (
                <Card>
                    <p className="text-sm text-slate-600">
                        This process is {process.status} and can no longer be updated.
                    </p>
                </Card>
            ) : (
                <form onSubmit={submit}>
                    <Card>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <SelectField
                                label="Status"
                                name="status"
                                value={form.status}
                                onChange={(e) => set('status', e.target.value as LifecycleProcessEditPayload['status'])}
                                error={fieldError('status')}
                            >
                                {allowedNextStates[process.status].map((status) => (
                                    <option key={status} value={status}>
                                        {status.replace('_', ' ')}
                                    </option>
                                ))}
                            </SelectField>
                            <InputField
                                label="Start date"
                                name="started_at"
                                type="date"
                                value={form.started_at}
                                onChange={(e) => set('started_at', e.target.value)}
                                error={fieldError('started_at')}
                            />
                            <InputField
                                label="Due date"
                                name="due_date"
                                type="date"
                                value={form.due_date}
                                onChange={(e) => set('due_date', e.target.value)}
                                error={fieldError('due_date')}
                            />
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <Button type="button" variant="secondary" onClick={() => router.visit(`/lifecycle/${processId}`)}>
                                Back to process
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Saving…' : 'Save changes'}
                            </Button>
                        </div>
                    </Card>
                </form>
            )}
        </AppLayout>
    );
}
