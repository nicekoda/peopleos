import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { formatActorRef } from '@/lib/format';
import { AuditLog, AuditLogSeverity, PaginatedResponse } from '@/types/auditLog';
import { User } from '@/types/user';

const severityTone: Record<AuditLogSeverity, 'neutral' | 'success' | 'warning' | 'danger'> = {
    info: 'neutral',
    warning: 'warning',
    critical: 'danger',
};

interface Filters {
    module: string;
    action: string;
    severity: string;
    date_from: string;
    date_to: string;
}

const emptyFilters: Filters = { module: '', action: '', severity: '', date_from: '', date_to: '' };

/**
 * Audit log data is fetched client-side from the new, tenant-filtered
 * /api/v1/audit-logs endpoint (Checkpoint 24) — this list can never
 * include another tenant's entries or a platform-level event, since the
 * backend manually filters both out (AuditLog has no BelongsToTenant
 * global scope to rely on — see docs/security.md). Actor/target names
 * are resolved client-side from a /api/v1/users lookup map, never a new
 * backend join (Refinement 7). metadata/old_values/new_values are never
 * rendered on this list — only on the detail page, and only already
 * sanitized (AuditValueSanitizer, server-side).
 */
export default function SettingsAuditLogs() {
    const [logs, setLogs] = useState<AuditLog[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [usersById, setUsersById] = useState<Map<number, string>>(new Map());
    const [filters, setFilters] = useState<Filters>(emptyFilters);
    const [appliedFilters, setAppliedFilters] = useState<Filters>(emptyFilters);

    const load = useCallback(() => {
        setError(null);
        const params = Object.fromEntries(Object.entries(appliedFilters).filter(([, value]) => value !== ''));

        api.get<PaginatedResponse<AuditLog>>('/audit-logs', { params })
            .then((response) => setLogs(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [appliedFilters]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        api.get<PaginatedResponse<User>>('/users')
            .then((response) => setUsersById(new Map(response.data.data.map((user) => [user.id, user.name]))))
            .catch(() => setUsersById(new Map()));
    }, []);

    const applyFilters = () => {
        setLogs(null);
        setAppliedFilters(filters);
    };

    const clearFilters = () => {
        setFilters(emptyFilters);
        setLogs(null);
        setAppliedFilters(emptyFilters);
    };

    const setFilter = <K extends keyof Filters>(key: K, value: Filters[K]) => {
        setFilters((prev) => ({ ...prev, [key]: value }));
    };

    const actorLookup = useMemo(() => usersById, [usersById]);

    return (
        <AppLayout>
            <Head title="Audit Logs" />

            <PageHeader
                title="Audit Logs"
                description="Read-only history of security-relevant activity in this tenant."
                actions={
                    <Link href="/settings/security" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Security &amp; Audit
                    </Link>
                }
            />

            <Card title="Filters" className="mb-6">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div>
                        <label htmlFor="module" className="block text-sm font-medium text-slate-700">
                            Module
                        </label>
                        <input
                            id="module"
                            value={filters.module}
                            onChange={(e) => setFilter('module', e.target.value)}
                            placeholder="e.g. leave"
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label htmlFor="action" className="block text-sm font-medium text-slate-700">
                            Action
                        </label>
                        <input
                            id="action"
                            value={filters.action}
                            onChange={(e) => setFilter('action', e.target.value)}
                            placeholder="e.g. role.assigned"
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label htmlFor="severity" className="block text-sm font-medium text-slate-700">
                            Severity
                        </label>
                        <select
                            id="severity"
                            value={filters.severity}
                            onChange={(e) => setFilter('severity', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        >
                            <option value="">Any</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="date_from" className="block text-sm font-medium text-slate-700">
                            From
                        </label>
                        <input
                            id="date_from"
                            type="date"
                            value={filters.date_from}
                            onChange={(e) => setFilter('date_from', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label htmlFor="date_to" className="block text-sm font-medium text-slate-700">
                            To
                        </label>
                        <input
                            id="date_to"
                            type="date"
                            value={filters.date_to}
                            onChange={(e) => setFilter('date_to', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                    </div>
                </div>
                <div className="mt-4 flex justify-end gap-3">
                    <button
                        type="button"
                        onClick={clearFilters}
                        className="rounded-md bg-white px-3.5 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                    >
                        Clear
                    </button>
                    <button
                        type="button"
                        onClick={applyFilters}
                        className="rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                    >
                        Apply filters
                    </button>
                </div>
            </Card>

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {logs === null && !error && <LoadingState label="Loading audit logs…" />}

            {logs !== null && logs.length === 0 && (
                <EmptyState title="No audit log entries found" description="Try adjusting or clearing the filters above." />
            )}

            {logs !== null && logs.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Timestamp</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Module</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Action</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Severity</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Actor</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Target</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Description</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {logs.map((log) => (
                                <tr key={log.id}>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {log.created_at.slice(0, 19).replace('T', ' ')}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{log.module}</td>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{log.action}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={severityTone[log.severity]}>{log.severity}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {formatActorRef(log.actor_user_id, actorLookup)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {log.target_user_id ? formatActorRef(log.target_user_id, actorLookup) : '—'}
                                    </td>
                                    <td className="max-w-xs truncate px-4 py-3 text-slate-500">{log.description ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <Link
                                            href={`/settings/security/audit-logs/${log.id}`}
                                            className="text-indigo-600 hover:text-indigo-500"
                                        >
                                            View
                                        </Link>
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
