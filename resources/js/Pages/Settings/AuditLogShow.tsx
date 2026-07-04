import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { formatActorRef } from '@/lib/format';
import { AuditLog, AuditLogSeverity, PaginatedResponse } from '@/types/auditLog';
import { User } from '@/types/user';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    auditLogId: number;
}

const severityTone: Record<AuditLogSeverity, 'neutral' | 'success' | 'warning' | 'danger'> = {
    info: 'neutral',
    warning: 'warning',
    critical: 'danger',
};

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Renders a sanitized key/value object as clean, escaped rows — never
 * dangerouslySetInnerHTML, never a raw JSON.stringify debug dump.
 * Values are already sanitized server-side (AuditValueSanitizer) before
 * this component ever sees them; this only ever needs to render text.
 */
function KeyValueList({ values }: { values: Record<string, unknown> | null }) {
    if (!values || Object.keys(values).length === 0) {
        return <p className="text-sm text-slate-500">None recorded.</p>;
    }

    return (
        <dl className="divide-y divide-slate-100">
            {Object.entries(values).map(([key, value]) => (
                <div key={key} className="flex justify-between gap-4 py-2 text-sm">
                    <dt className="text-slate-500">{key}</dt>
                    <dd className="break-all text-right font-medium text-slate-900">
                        {value === null ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default function SettingsAuditLogShow() {
    const { auditLogId } = usePage<ShowProps>().props;

    const [log, setLog] = useState<AuditLog | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [usersById, setUsersById] = useState<Map<number, string>>(new Map());

    useEffect(() => {
        api.get<{ data: AuditLog }>(`/audit-logs/${auditLogId}`)
            .then((response) => setLog(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [auditLogId]);

    useEffect(() => {
        api.get<PaginatedResponse<User>>('/users')
            .then((response) => setUsersById(new Map(response.data.data.map((user) => [user.id, user.name]))))
            .catch(() => setUsersById(new Map()));
    }, []);

    if (error) {
        return (
            <AppLayout>
                <Head title="Audit Log" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!log) {
        return (
            <AppLayout>
                <Head title="Audit Log" />
                <LoadingState label="Loading audit log…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={`Audit log #${log.id}`} />

            <PageHeader
                title={log.action}
                description={
                    <>
                        {log.module} · <Badge tone={severityTone[log.severity]}>{log.severity}</Badge>
                    </>
                }
                actions={
                    <Link href="/settings/security/audit-logs" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Audit Logs
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Timestamp" value={log.created_at.slice(0, 19).replace('T', ' ')} />
                        <Field label="Actor" value={formatActorRef(log.actor_user_id, usersById)} />
                        <Field label="Target" value={log.target_user_id ? formatActorRef(log.target_user_id, usersById) : null} />
                        <Field label="Description" value={log.description} />
                    </dl>
                </Card>

                <Card title="Reference">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Auditable type" value={log.auditable_type} />
                        <Field label="Auditable ID" value={log.auditable_id} />
                    </dl>
                </Card>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Card title="Metadata">
                    <KeyValueList values={log.metadata} />
                </Card>
                <Card title="Previous values">
                    <KeyValueList values={log.old_values} />
                </Card>
                <Card title="New values">
                    <KeyValueList values={log.new_values} />
                </Card>
            </div>
        </AppLayout>
    );
}
