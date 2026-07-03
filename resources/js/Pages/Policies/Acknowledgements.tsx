import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { formatEmployeeRef } from '@/lib/format';
import { PaginatedResponse, Policy, PolicyAcknowledgement } from '@/types/policy';
import { PageProps } from '@/types';

interface AcknowledgementsProps extends PageProps {
    policyId: string;
}

const statusTone: Record<PolicyAcknowledgement['acknowledgement_status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    pending: 'neutral',
    acknowledged: 'success',
    overdue: 'danger',
    waived: 'warning',
};

/**
 * Employee references use formatEmployeeRef() (Checkpoint 18) rather
 * than rendering the raw employee_id prominently — PolicyAcknowledgementResource
 * has no employee name field. ip_address/user_agent/assigned_by are
 * returned by the API but deliberately never rendered here — unnecessary
 * technical/internal-actor data for this screen (Refinement 6).
 */
export default function PolicyAcknowledgements() {
    const { policyId } = usePage<AcknowledgementsProps>().props;
    const viewerEmployeeId = usePage<AcknowledgementsProps>().props.auth.user?.employee_id ?? null;

    const [policy, setPolicy] = useState<Policy | null>(null);
    const [acknowledgements, setAcknowledgements] = useState<PolicyAcknowledgement[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const load = useCallback(() => {
        setError(null);
        Promise.all([
            api.get<{ data: Policy }>(`/policies/${policyId}`),
            api.get<PaginatedResponse<PolicyAcknowledgement>>(`/policies/${policyId}/acknowledgements`),
        ])
            .then(([policyResponse, acknowledgementsResponse]) => {
                setPolicy(policyResponse.data.data);
                setAcknowledgements(acknowledgementsResponse.data.data);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [policyId]);

    useEffect(() => {
        load();
    }, [load]);

    if (error) {
        return (
            <AppLayout>
                <Head title="Acknowledgements" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!policy || acknowledgements === null) {
        return (
            <AppLayout>
                <Head title="Acknowledgements" />
                <LoadingState label="Loading acknowledgements…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={`${policy.title} — Acknowledgements`} />

            <PageHeader
                title={`Acknowledgements — ${policy.title}`}
                actions={
                    <Link href={`/policies/${policyId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to policy
                    </Link>
                }
            />

            {acknowledgements.length === 0 && (
                <EmptyState title="No acknowledgement records yet" description="Assign this policy to employees to start tracking acknowledgements." />
            )}

            {acknowledgements.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Employee</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Assigned</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Due</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Acknowledged</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Method</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {acknowledgements.map((ack) => (
                                <tr key={ack.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        {formatEmployeeRef(ack.employee_id, viewerEmployeeId)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[ack.acknowledgement_status]}>{ack.acknowledgement_status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{ack.assigned_at?.slice(0, 10) ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{ack.due_date ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{ack.acknowledged_at?.slice(0, 10) ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{ack.acknowledgement_method ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
