import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import LoadingState from '@/Components/LoadingState';
import EmptyState from '@/Components/EmptyState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LifecycleProcess, LifecycleProcessStatus, LifecycleTask, LifecycleTaskStatus } from '@/types/lifecycle';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    processId: string;
}

const processStatusTone: Record<LifecycleProcessStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    in_progress: 'warning',
    completed: 'success',
    cancelled: 'danger',
};

const taskStatusTone: Record<LifecycleTaskStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    pending: 'neutral',
    in_progress: 'warning',
    completed: 'success',
    skipped: 'danger',
};

const typeLabel: Record<string, string> = {
    onboarding: 'Onboarding',
    offboarding: 'Offboarding',
};

/**
 * Checkpoint 33 — process + task detail, fetched client-side from
 * /api/v1/lifecycle-processes/{id} (which eager-loads tasks). Complete/
 * Skip buttons render whenever the viewer holds lifecycle.complete_task
 * — same posture as Leave Management UI's Approve/Reject buttons: the
 * frontend cannot know LifecycleVisibilityService's per-task scope, so
 * a 403 from a button that looked available is a normal, expected
 * outcome, not routed around.
 */
export default function LifecycleShow() {
    const { processId } = usePage<ShowProps>().props;

    const [process, setProcess] = useState<LifecycleProcess | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [actioningTaskId, setActioningTaskId] = useState<string | null>(null);
    const [archiving, setArchiving] = useState(false);

    const load = useCallback(() => {
        setError(null);
        api.get<{ data: LifecycleProcess }>(`/lifecycle-processes/${processId}`)
            .then((response) => setProcess(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [processId]);

    useEffect(() => {
        load();
    }, [load]);

    const runTaskAction = (task: LifecycleTask, action: 'complete' | 'skip') => {
        setActioningTaskId(task.id);
        setError(null);

        api.post(`/lifecycle-tasks/${task.id}/${action}`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setActioningTaskId(null));
    };

    const handleArchive = () => {
        if (!process) return;
        if (!window.confirm('Cancel/archive this lifecycle process? This cannot be undone.')) {
            return;
        }

        setArchiving(true);
        setError(null);

        api.delete(`/lifecycle-processes/${process.id}`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setArchiving(false));
    };

    if (error) {
        return (
            <AppLayout>
                <Head title="Lifecycle process" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!process) {
        return (
            <AppLayout>
                <Head title="Lifecycle process" />
                <LoadingState label="Loading lifecycle process…" />
            </AppLayout>
        );
    }

    const isTerminal = process.status === 'completed' || process.status === 'cancelled';
    const tasks = process.tasks ?? [];

    return (
        <AppLayout>
            <Head title={`${typeLabel[process.type]} — ${process.employee?.full_name ?? ''}`} />

            <PageHeader
                title={`${typeLabel[process.type]} — ${process.employee?.full_name ?? 'Unknown employee'}`}
                description={`Status: ${process.status.replace('_', ' ')}`}
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/lifecycle" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Lifecycle
                        </Link>
                        <PermissionGate permission="lifecycle.update">
                            <Link
                                href={`/lifecycle/${process.id}/edit`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Edit
                            </Link>
                        </PermissionGate>
                        {!isTerminal && (
                            <PermissionGate permission="lifecycle.delete">
                                <button
                                    type="button"
                                    onClick={handleArchive}
                                    disabled={archiving}
                                    className="text-sm font-medium text-red-600 hover:text-red-500 disabled:opacity-50"
                                >
                                    {archiving ? 'Cancelling…' : 'Cancel process'}
                                </button>
                            </PermissionGate>
                        )}
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Type</dt>
                            <dd className="font-medium text-slate-900">{typeLabel[process.type]}</dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Status</dt>
                            <dd>
                                <Badge tone={processStatusTone[process.status]}>{process.status.replace('_', ' ')}</Badge>
                            </dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Started</dt>
                            <dd className="font-medium text-slate-900">{process.started_at?.slice(0, 10) ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Due date</dt>
                            <dd className="font-medium text-slate-900">{process.due_date ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Completed</dt>
                            <dd className="font-medium text-slate-900">{process.completed_at?.slice(0, 10) ?? '—'}</dd>
                        </div>
                    </dl>
                </Card>
            </div>

            <div className="mt-4">
                <Card>
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-900">Tasks</h3>
                        {!isTerminal && (
                            <PermissionGate permission="lifecycle.create">
                                <Link
                                    href={`/lifecycle/${process.id}/tasks/create`}
                                    className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                >
                                    Add task
                                </Link>
                            </PermissionGate>
                        )}
                    </div>
                    {tasks.length === 0 ? (
                        <EmptyState title="No tasks yet" description="Add tasks to track onboarding/offboarding progress." />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-600">Task</th>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-600">Assignee</th>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-600">Status</th>
                                        <th className="px-3 py-2 text-left font-semibold text-slate-600">Due date</th>
                                        <th className="px-3 py-2 text-right font-semibold text-slate-600">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {tasks.map((task) => {
                                        const taskIsTerminal = task.status === 'completed' || task.status === 'skipped';

                                        return (
                                            <tr key={task.id}>
                                                <td className="px-3 py-2 font-medium text-slate-900">{task.title}</td>
                                                <td className="px-3 py-2 text-slate-500">{task.assigned_to?.name ?? '—'}</td>
                                                <td className="whitespace-nowrap px-3 py-2">
                                                    <Badge tone={taskStatusTone[task.status]}>{task.status.replace('_', ' ')}</Badge>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-2 text-slate-500">{task.due_date ?? '—'}</td>
                                                <td className="whitespace-nowrap px-3 py-2 text-right">
                                                    <div className="flex justify-end gap-3">
                                                        {!taskIsTerminal && !isTerminal && (
                                                            <PermissionGate permission="lifecycle.complete_task">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => runTaskAction(task, 'complete')}
                                                                    disabled={actioningTaskId === task.id}
                                                                    className="text-green-700 hover:text-green-600 disabled:opacity-50"
                                                                >
                                                                    Complete
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => runTaskAction(task, 'skip')}
                                                                    disabled={actioningTaskId === task.id}
                                                                    className="text-slate-600 hover:text-slate-500 disabled:opacity-50"
                                                                >
                                                                    Skip
                                                                </button>
                                                            </PermissionGate>
                                                        )}
                                                        {!taskIsTerminal && !isTerminal && (
                                                            <PermissionGate permission="lifecycle.update">
                                                                <Link
                                                                    href={`/lifecycle/tasks/${task.id}/edit`}
                                                                    className="text-indigo-600 hover:text-indigo-500"
                                                                >
                                                                    Edit
                                                                </Link>
                                                            </PermissionGate>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
