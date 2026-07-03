import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LeaveRequest, LeaveRequestFormPayload, LeaveType, PaginatedResponse } from '@/types/leave';

/**
 * POST /leave-requests only ever creates a draft (Checkpoint 12) —
 * submitting for approval is a separate action. This form is
 * deliberately labelled "Save Draft," not "Submit Request" (Refinement
 * 3), and redirects to the detail page afterwards, where a clear
 * "Submit for approval" action is available.
 *
 * Payload is built field-by-field from LeaveRequestFormPayload, never
 * by spreading a broader object — total_days/employee_id/status are
 * never fields on this form at all, matching what
 * StoreLeaveRequestRequest actually accepts.
 */
export default function LeaveCreate() {
    const [leaveTypes, setLeaveTypes] = useState<LeaveType[] | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);

    const [form, setForm] = useState<LeaveRequestFormPayload>({
        leave_type_id: '',
        start_date: '',
        end_date: '',
        reason: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<PaginatedResponse<LeaveType>>('/leave-types')
            .then((response) => setLeaveTypes(response.data.data.filter((type) => type.status === 'active')))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, []);

    // Estimated only — the backend independently (and authoritatively)
    // computes total_days the same way (inclusive calendar days); this
    // is a UI preview, never sent to the API.
    const estimatedDays = useMemo(() => {
        if (!form.start_date || !form.end_date) {
            return null;
        }
        const start = new Date(form.start_date);
        const end = new Date(form.end_date);
        const diff = Math.round((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24)) + 1;

        return diff > 0 ? diff : null;
    }, [form.start_date, form.end_date]);

    const set = <K extends keyof LeaveRequestFormPayload>(key: K, value: LeaveRequestFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));

        api.post<{ data: LeaveRequest }>('/leave-requests', payload)
            .then((response) => {
                router.visit(`/leave/${response.data.data.id}`);
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
                <Head title="Request leave" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Request leave" />

            <PageHeader title="Request leave" description="This creates a draft — you'll submit it for approval afterwards." />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Leave type"
                            name="leave_type_id"
                            required
                            value={form.leave_type_id}
                            onChange={(e) => set('leave_type_id', e.target.value)}
                            error={fieldError('leave_type_id')}
                        >
                            <option value="">Select a leave type…</option>
                            {leaveTypes?.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </SelectField>
                        <div />

                        <InputField
                            label="Start date"
                            name="start_date"
                            type="date"
                            required
                            value={form.start_date}
                            onChange={(e) => set('start_date', e.target.value)}
                            error={fieldError('start_date')}
                        />
                        <InputField
                            label="End date"
                            name="end_date"
                            type="date"
                            required
                            value={form.end_date}
                            onChange={(e) => set('end_date', e.target.value)}
                            error={fieldError('end_date')}
                        />
                    </div>

                    {estimatedDays !== null && (
                        <p className="mt-2 text-sm text-slate-500">
                            Estimated: {estimatedDays} calendar day{estimatedDays === 1 ? '' : 's'} (the backend calculates the
                            final total).
                        </p>
                    )}

                    <div className="mt-4">
                        <label htmlFor="reason" className="block text-sm font-medium text-slate-700">
                            Reason
                        </label>
                        <textarea
                            id="reason"
                            rows={3}
                            value={form.reason}
                            onChange={(e) => set('reason', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                        <ErrorMessage message={fieldError('reason')} />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting || leaveTypes === null}>
                            {submitting ? 'Saving…' : 'Save draft'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
