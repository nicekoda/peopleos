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

interface TaskEditProps extends PageProps {
    taskId: string;
    processId: string;
}

/**
 * There is no standalone GET /api/v1/lifecycle-tasks/{task} endpoint
 * (not in the approved API surface) — the task's current data is found
 * client-side within the already-approved GET
 * /api/v1/lifecycle-processes/{process} response (which eager-loads
 * tasks), rather than adding a new route for this one form.
 */
export default function LifecycleTaskEdit() {
    const { taskId, processId } = usePage<TaskEditProps>().props;
    const canAssign = useCan('lifecycle.assign_task');

    const [process, setProcess] = useState<LifecycleProcess | null>(null);
    const [task, setTask] = useState<LifecycleTask | null>(null);
    const [users, setUsers] = useState<User[] | null>(null);
    const [form, setForm] = useState<LifecycleTaskFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: LifecycleProcess }>(`/lifecycle-processes/${processId}`)
            .then((response) => {
                const data = response.data.data;
                setProcess(data);
                const found = (data.tasks ?? []).find((t) => t.id === taskId) ?? null;
                setTask(found);
                if (found) {
                    setForm({
                        title: found.title,
                        description: found.description ?? '',
                        assigned_to_user_id: found.assigned_to_user_id ? String(found.assigned_to_user_id) : '',
                        due_date: found.due_date ?? '',
                    });
                }
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [processId, taskId]);

    useEffect(() => {
        if (!canAssign) return;
        api.get<UserPaginatedResponse<User>>('/users')
            .then((response) => setUsers(response.data.data))
            .catch(() => setUsers([]));
    }, [canAssign]);

    const set = <K extends keyof LifecycleTaskFormPayload>(key: K, value: LifecycleTaskFormPayload[K]) => {
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
            title: form.title,
            description: form.description === '' ? null : form.description,
            assigned_to_user_id: form.assigned_to_user_id === '' ? null : form.assigned_to_user_id,
            due_date: form.due_date === '' ? null : form.due_date,
        };

        api.patch<{ data: LifecycleTask }>(`/lifecycle-tasks/${taskId}`, payload)
            .then(() => setSuccessMessage('Task updated.'))
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
                <Head title="Edit task" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!process || !task || !form) {
        return (
            <AppLayout>
                <Head title="Edit task" />
                <LoadingState label="Loading task…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit task" />

            <PageHeader
                title="Edit task"
                description={process.employee?.full_name ?? undefined}
                actions={
                    <Link href={`/lifecycle/${processId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to process
                    </Link>
                }
            />

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
                        <Button type="button" variant="secondary" onClick={() => router.visit(`/lifecycle/${processId}`)}>
                            Back to process
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
