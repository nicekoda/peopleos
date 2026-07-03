import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import RejectReasonPrompt from '@/Components/RejectReasonPrompt';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { formatEmployeeRef } from '@/lib/format';
import { LeaveRequest, LeaveType } from '@/types/leave';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    leaveRequestId: string;
}

const statusTone: Record<LeaveRequest['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    pending: 'warning',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

const successMessages: Record<string, string> = {
    submit: 'Leave request submitted.',
    approve: 'Leave request approved.',
    reject: 'Leave request rejected.',
    cancel: 'Leave request cancelled.',
};

function Field({ label, value }: { label: string; value: string | number | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Every action below (submit/cancel/approve/reject) is a thin wrapper
 * around the existing, already-tested /api/v1/leave-requests/{id}/...
 * endpoints. The frontend cannot know the full manager-scope rule
 * (ManagerHierarchyService::directlyManages()) — Approve/Reject render
 * whenever the viewer holds the permission and the request is pending,
 * and a resulting 403 (not actually this employee's direct manager, or
 * no HR-wide scope) is handled the same safe way as any other 403. See
 * docs/security.md.
 */
export default function LeaveShow() {
    const { leaveRequestId, auth } = usePage<ShowProps>().props;
    const viewerEmployeeId = auth.user?.employee_id ?? null;
    const canRequest = useCan('leave.request');
    const canCancelPermission = useCan('leave.cancel');
    const canApprovePermission = useCan('leave.approve');
    const canRejectPermission = useCan('leave.reject');

    const [leaveRequest, setLeaveRequest] = useState<LeaveRequest | null>(null);
    const [leaveTypeName, setLeaveTypeName] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);

    const [actionError, setActionError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [processing, setProcessing] = useState<string | null>(null);
    const [showRejectPrompt, setShowRejectPrompt] = useState(false);
    const [rejectError, setRejectError] = useState<string | undefined>(undefined);

    const load = useCallback(() => {
        api.get<{ data: LeaveRequest }>(`/leave-requests/${leaveRequestId}`)
            .then((response) => {
                setLeaveRequest(response.data.data);
                return api.get<{ data: LeaveType }>(`/leave-types/${response.data.data.leave_type_id}`);
            })
            .then((response) => setLeaveTypeName(response.data.data.name))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [leaveRequestId]);

    useEffect(() => {
        load();
    }, [load]);

    const runAction = (action: string, request: () => Promise<unknown>) => {
        setProcessing(action);
        setActionError(null);
        setSuccessMessage(null);

        request()
            .then(() => {
                setSuccessMessage(successMessages[action] ?? 'Done.');
                setShowRejectPrompt(false);
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (action === 'reject') {
                    setRejectError(apiError.errors?.rejection_reason?.[0] ?? apiError.message);
                } else {
                    setActionError(apiError.message);
                }
            })
            .finally(() => setProcessing(null));
    };

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Leave request" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!leaveRequest) {
        return (
            <AppLayout>
                <Head title="Leave request" />
                <LoadingState label="Loading leave request…" />
            </AppLayout>
        );
    }

    const isOwn = viewerEmployeeId !== null && leaveRequest.employee_id === viewerEmployeeId;
    const canSubmit = isOwn && canRequest && leaveRequest.status === 'draft';
    const canCancel = isOwn && canCancelPermission && (leaveRequest.status === 'draft' || leaveRequest.status === 'pending');
    const canApprove = !isOwn && canApprovePermission && leaveRequest.status === 'pending';
    const canReject = !isOwn && canRejectPermission && leaveRequest.status === 'pending';

    return (
        <AppLayout>
            <Head title="Leave request" />

            <PageHeader
                title={leaveTypeName ?? 'Leave request'}
                description={formatEmployeeRef(leaveRequest.employee_id, viewerEmployeeId)}
            />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {actionError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{actionError}</div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Details">
                    <dl className="divide-y divide-slate-100">
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Status</dt>
                            <dd>
                                <Badge tone={statusTone[leaveRequest.status]}>{leaveRequest.status}</Badge>
                            </dd>
                        </div>
                        <Field label="Start date" value={leaveRequest.start_date} />
                        <Field label="End date" value={leaveRequest.end_date} />
                        <Field label="Total days" value={leaveRequest.total_days} />
                        <Field label="Reason" value={leaveRequest.reason} />
                    </dl>
                </Card>

                <Card title="Workflow history">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Submitted" value={leaveRequest.submitted_at?.slice(0, 10)} />
                        <Field label="Approved" value={leaveRequest.approved_at?.slice(0, 10)} />
                        <Field label="Rejected" value={leaveRequest.rejected_at?.slice(0, 10)} />
                        {leaveRequest.status === 'rejected' && (
                            <Field label="Rejection reason" value={leaveRequest.rejection_reason} />
                        )}
                        <Field label="Cancelled" value={leaveRequest.cancelled_at?.slice(0, 10)} />
                    </dl>
                </Card>
            </div>

            <div className="mt-6 flex flex-wrap gap-3">
                {canSubmit && (
                    <PermissionGate permission="leave.request">
                        <Button disabled={processing !== null} onClick={() => runAction('submit', () => api.post(`/leave-requests/${leaveRequest.id}/submit`))}>
                            {processing === 'submit' ? 'Submitting…' : 'Submit for approval'}
                        </Button>
                    </PermissionGate>
                )}

                {canApprove && (
                    <PermissionGate permission="leave.approve">
                        <Button
                            disabled={processing !== null}
                            onClick={() => runAction('approve', () => api.post(`/leave-requests/${leaveRequest.id}/approve`))}
                        >
                            {processing === 'approve' ? 'Approving…' : 'Approve'}
                        </Button>
                    </PermissionGate>
                )}

                {canReject && !showRejectPrompt && (
                    <PermissionGate permission="leave.reject">
                        <Button variant="danger" disabled={processing !== null} onClick={() => setShowRejectPrompt(true)}>
                            Reject
                        </Button>
                    </PermissionGate>
                )}

                {canCancel && (
                    <PermissionGate permission="leave.cancel">
                        <Button
                            variant="secondary"
                            disabled={processing !== null}
                            onClick={() => runAction('cancel', () => api.post(`/leave-requests/${leaveRequest.id}/cancel`))}
                        >
                            {processing === 'cancel' ? 'Cancelling…' : 'Cancel request'}
                        </Button>
                    </PermissionGate>
                )}
            </div>

            {canReject && showRejectPrompt && (
                <div className="mt-4">
                    <RejectReasonPrompt
                        submitting={processing === 'reject'}
                        error={rejectError}
                        onCancel={() => {
                            setShowRejectPrompt(false);
                            setRejectError(undefined);
                        }}
                        onConfirm={(reason) =>
                            runAction('reject', () => api.post(`/leave-requests/${leaveRequest.id}/reject`, { rejection_reason: reason }))
                        }
                    />
                </div>
            )}
        </AppLayout>
    );
}
