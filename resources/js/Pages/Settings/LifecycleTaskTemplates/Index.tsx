import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LifecycleTaskTemplate, LifecycleProcessTypeValue, PaginatedResponse } from '@/types/lifecycleTaskTemplate';

const typeTone: Record<LifecycleProcessTypeValue, 'neutral' | 'success' | 'warning' | 'danger'> = {
    onboarding: 'success',
    offboarding: 'warning',
};

/**
 * Checkpoint 42 — template data is fetched client-side from
 * /api/v1/lifecycle-task-templates, already tenant-isolated via
 * BelongsToTenant. "Archive" (not "Delete") because the backend action
 * is soft-delete-only — an archived template simply stops being copied
 * into newly started processes; it never affects tasks already
 * generated from it (see docs/architecture.md).
 */
export default function SettingsLifecycleTaskTemplatesIndex() {
    const [templates, setTemplates] = useState<LifecycleTaskTemplate[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [archivingId, setArchivingId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<LifecycleTaskTemplate>>('/lifecycle-task-templates')
            .then((response) => setTemplates(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const handleArchive = (template: LifecycleTaskTemplate) => {
        if (!window.confirm(`Archive "${template.title}"? It will no longer be applied to newly started ${template.type} processes.`)) {
            return;
        }

        setArchivingId(template.id);
        setError(null);

        api.delete(`/lifecycle-task-templates/${template.id}`)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setArchivingId(null));
    };

    return (
        <AppLayout>
            <Head title="Onboarding & Offboarding Task Templates" />

            <PageHeader
                title="Onboarding & Offboarding Task Templates"
                description="Default tasks automatically added whenever a new onboarding or offboarding process is started."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Settings
                        </Link>
                        <PermissionGate permission="lifecycle_task_templates.create">
                            <Link
                                href="/settings/lifecycle-task-templates/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Add template
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {templates === null && !error && <LoadingState label="Loading task templates…" />}

            {templates !== null && templates.length === 0 && (
                <EmptyState
                    title="No task templates yet"
                    description="Newly started onboarding/offboarding processes will have no default tasks until you add some here."
                />
            )}

            {templates !== null && templates.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Due</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Order</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {templates.map((template) => (
                                <tr key={template.id}>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={typeTone[template.type]}>{template.type}</Badge>
                                    </td>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {template.title}
                                        {template.description && (
                                            <p className="mt-0.5 max-w-md truncate text-xs font-normal text-slate-500">{template.description}</p>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {template.due_in_days === null ? '—' : `${template.due_in_days} day(s)`}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{template.sort_order}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <div className="flex justify-end gap-3">
                                            <PermissionGate permission="lifecycle_task_templates.update">
                                                <Link
                                                    href={`/settings/lifecycle-task-templates/${template.id}/edit`}
                                                    className="text-indigo-600 hover:text-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                            </PermissionGate>
                                            <PermissionGate permission="lifecycle_task_templates.delete">
                                                <button
                                                    type="button"
                                                    onClick={() => handleArchive(template)}
                                                    disabled={archivingId === template.id}
                                                    className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                >
                                                    {archivingId === template.id ? 'Archiving…' : 'Archive'}
                                                </button>
                                            </PermissionGate>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
