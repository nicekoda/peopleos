import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { User, PaginatedResponse as UserPaginatedResponse } from '@/types/user';
import { LifecycleProcess, LifecycleTask, LifecycleTaskFormPayload } from '@/types/lifecycle';
import { PageProps } from '@/types';

interface TaskCreateProps extends PageProps {
    processId: string;
}

/**
 * title/description/assigned_to_user_id/due_date only — process_id
 * always comes from the route, never a form field. Setting an assignee
 * additionally requires lifecycle.assign_task (enforced server-side);
 * the picker is simply hidden here if the viewer doesn't hold it, per
 * Refinement 9.
 */
export default function LifecycleTaskCreate() {
    const { processId } = usePage<TaskCreateProps>().props;
    const canAssign = useCan('lifecycle.assign_task');

    const [process, setProcess] = useState<LifecycleProcess | null>(null);
    const [users, setUsers] = useState<User[] | null>(null);
    const [form, setForm] = useState<LifecycleTaskFormPayload>({
        title: '',
        description: '',
        assigned_to_user_id: '',
        due_date: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: LifecycleProcess }>(`/lifecycle-processes/${processId}`)
            .then((response) => setProcess(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [processId]);

    useEffect(() => {
        if (!canAssign) return;
        api.get<UserPaginatedResponse<User>>('/users')
            .then((response) => setUsers(response.data.data))
            .catch(() => setUsers([]));
    }, [canAssign]);

    const set = <K extends keyof LifecycleTaskFormPayload>(key: K, value: LifecycleTaskFormPayload[K]) => {
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
            ...(form.assigned_to_user_id ? { assigned_to_user_id: form.assigned_to_user_id } : {}),
            ...(form.due_date ? { due_date: form.due_date } : {}),
        };

        api.post<{ data: LifecycleTask }>(`/lifecycle-processes/${processId}/tasks`, payload)
            .then(() => {
                router.visit(`/lifecycle/${processId}`);
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
                <Head title="Add task" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!process) {
        return (
            <AppLayout>
                <Head title="Add task" />
                <LoadingState label="Loading process…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Add task" />

            <PageHeader
                title="Add task"
                description={process.employee?.full_name ?? undefined}
                actions={
                    <Link href={`/lifecycle/${processId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to process
                    </Link>
                }
            />

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
                            className="sm:col-span-2"
                            value={form.title}
                            onChange={(e) => set('title', e.target.value)}
                            error={fieldError('title')}
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

                        {canAssign && (
                            <SelectField
                                label="Assignee"
                                name="assigned_to_user_id"
                                value={form.assigned_to_user_id}
                                onChange={(e) => set('assigned_to_user_id', e.target.value)}
                                error={fieldError('assigned_to_user_id')}
                            >
                                <option value="">— Unassigned —</option>
                                {(users ?? []).map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </SelectField>
                        )}

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
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Adding…' : 'Add task'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
