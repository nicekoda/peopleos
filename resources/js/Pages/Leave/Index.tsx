import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { formatEmployeeRef } from '@/lib/format';
import { LeaveRequest, LeaveType, LeaveBalance, PaginatedResponse } from '@/types/leave';
import { PageProps } from '@/types';

const statusTone: Record<LeaveRequest['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

/**
 * Leave requests, leave types (for the name lookup — Refinement/plan
 * decision 2), and the viewer's own balances (Refinement 2: read-only
 * display only, no admin editing here) are each fetched independently,
 * client-side, from the existing /api/v1 endpoints — see
 * docs/architecture.md. Visibility of the requests list itself is
 * entirely backend-decided (leave.view / leave.view_team /
 * leave.view_all, see docs/security.md) — this page renders whatever
 * comes back, it does not compute or assume scope itself.
 */
export default function LeaveIndex() {
    const { auth } = usePage<PageProps>().props;
    const viewerEmployeeId = auth.user?.employee_id ?? null;

    const [requests, setRequests] = useState<LeaveRequest[] | null>(null);
    const [requestsError, setRequestsError] = useState<ApiError | null>(null);
    const [leaveTypes, setLeaveTypes] = useState<Record<string, string>>({});
    const [balances, setBalances] = useState<LeaveBalance[] | null>(null);
    const [balancesError, setBalancesError] = useState<ApiError | null>(null);

    const loadRequests = useCallback(() => {
        setRequestsError(null);
        api.get<PaginatedResponse<LeaveRequest>>('/leave-requests')
            .then((response) => setRequests(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setRequestsError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        loadRequests();

        api.get<PaginatedResponse<LeaveType>>('/leave-types')
            .then((response) => {
                const map: Record<string, string> = {};
                response.data.data.forEach((type) => {
                    map[type.id] = type.name;
                });
                setLeaveTypes(map);
            })
            .catch(() => {
                // Leave-type names are a display nicety, not required —
                // if this fails, the table falls back to a safe "—"
                // rather than blocking the whole page.
            });

        api.get<PaginatedResponse<LeaveBalance>>('/me/leave-balances')
            .then((response) => setBalances(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setBalancesError(apiError);
                }
            });
    }, [loadRequests]);

    return (
        <AppLayout>
            <Head title="Leave" />

            <PageHeader
                title="Leave"
                description="Leave requests and balances"
                actions={
                    <PermissionGate permission="leave.request">
                        <Link
                            href="/leave/create"
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            Request leave
                        </Link>
                    </PermissionGate>
                }
            />

            <div className="mb-8">
                <h2 className="mb-3 text-sm font-semibold text-slate-900">Your leave balances</h2>

                {balancesError && (
                    <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{balancesError.message}</div>
                )}

                {balances === null && !balancesError && <LoadingState label="Loading balances…" />}

                {balances !== null && balances.length === 0 && (
                    <EmptyState
                        title="No leave balances yet"
                        description="Balances appear once a leave request is submitted for a balance-controlled leave type, or an administrator creates one."
                    />
                )}

                {balances !== null && balances.length > 0 && (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {balances.map((balance) => (
                            <Card key={balance.id} title={`${leaveTypes[balance.leave_type_id] ?? 'Leave type'} (${balance.year})`}>
                                <dl className="space-y-1.5 text-sm">
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">Entitlement</dt>
                                        <dd className="font-medium text-slate-900">{balance.entitlement_days}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">Used</dt>
                                        <dd className="font-medium text-slate-900">{balance.used_days}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">Pending</dt>
                                        <dd className="font-medium text-slate-900">{balance.pending_days}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">Carried forward</dt>
                                        <dd className="font-medium text-slate-900">{balance.carried_forward_days}</dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-slate-500">Adjustment</dt>
                                        <dd className="font-medium text-slate-900">{balance.adjustment_days}</dd>
                                    </div>
                                    <div className="flex justify-between border-t border-slate-100 pt-1.5">
                                        <dt className="font-medium text-slate-700">Available</dt>
                                        <dd className="font-semibold text-slate-900">{balance.available_days}</dd>
                                    </div>
                                </dl>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            <h2 className="mb-3 text-sm font-semibold text-slate-900">Leave requests</h2>

            {requestsError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{requestsError.message}</div>
            )}

            {requests === null && !requestsError && <LoadingState label="Loading leave requests…" />}

            {requests !== null && requests.length === 0 && (
                <EmptyState
                    title="No leave requests"
                    description="Leave requests you can see will appear here."
                />
            )}

            {requests !== null && requests.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Employee</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Leave type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Start</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">End</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Days</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {requests.map((leaveRequest) => (
                                <tr key={leaveRequest.id}>
                                    <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500">
                                        {formatEmployeeRef(leaveRequest.employee_id, viewerEmployeeId)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-700">
                                        {leaveTypes[leaveRequest.leave_type_id] ?? '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{leaveRequest.start_date}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{leaveRequest.end_date}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{leaveRequest.total_days}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[leaveRequest.status]}>{leaveRequest.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                        <Link href={`/leave/${leaveRequest.id}`} className="text-indigo-600 hover:text-indigo-500">
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
