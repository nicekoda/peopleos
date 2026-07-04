import { Head, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LeaveType, LeaveTypeFormPayload } from '@/types/leaveType';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    leaveTypeId: string;
}

/**
 * `status` is the one field this form adds beyond Create. tenant_id/
 * created_by/updated_by/deleted_at/slug remain structurally absent
 * (Refinement 3).
 *
 * Refinement 4 — `max_days_per_year` is the one field on this form that
 * does NOT follow the usual "omit if blank" convention every other
 * optional field in this app's forms uses. Every other module's forms
 * treat "field left blank" as "don't change this" (the key is simply
 * absent from the payload). But for an *existing* leave type, that
 * would make it impossible to ever turn a capped leave type back into
 * an unlimited one — omitting the key leaves the old numeric cap in
 * place forever. So on Edit specifically, a blank field is sent as an
 * explicit `null` (not omitted), which the backend's own `nullable`
 * validation rule already accepts and applies. Changing this value
 * here never touches any existing LeaveBalance row — balances and
 * leave types are separate tables, and no code path cascades a
 * LeaveType update into rewriting balances already issued.
 */
export default function SettingsLeaveTypeEdit() {
    const { leaveTypeId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<LeaveTypeFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: LeaveType }>(`/leave-types/${leaveTypeId}`)
            .then((response) => {
                const leaveType = response.data.data;
                setForm({
                    name: leaveType.name,
                    description: leaveType.description ?? '',
                    is_paid: leaveType.is_paid,
                    requires_approval: leaveType.requires_approval,
                    requires_document: leaveType.requires_document,
                    max_days_per_year: leaveType.max_days_per_year === null ? '' : String(leaveType.max_days_per_year),
                    status: leaveType.status,
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [leaveTypeId]);

    const set = <K extends keyof LeaveTypeFormPayload>(key: K, value: LeaveTypeFormPayload[K]) => {
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
            name: form.name,
            description: form.description || null,
            is_paid: form.is_paid,
            requires_approval: form.requires_approval,
            requires_document: form.requires_document,
            // Explicit null when blank — never omitted — so clearing this
            // field genuinely turns the leave type back into unlimited,
            // rather than silently leaving the old cap in place.
            max_days_per_year: form.max_days_per_year === '' ? null : Number(form.max_days_per_year),
            status: form.status,
        };

        api.patch<{ data: LeaveType }>(`/leave-types/${leaveTypeId}`, payload)
            .then(() => setSuccessMessage('Leave type updated.'))
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
                <Head title="Edit leave type" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit leave type" />
                <LoadingState label="Loading leave type…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit leave type" />

            <PageHeader title="Edit leave type" />

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
                            label="Name"
                            name="name"
                            required
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={fieldError('name')}
                        />
                        <SelectField
                            label="Status"
                            name="status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value as LeaveTypeFormPayload['status'])}
                            error={fieldError('status')}
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </SelectField>

                        <div>
                            <InputField
                                label="Max days per year"
                                name="max_days_per_year"
                                type="number"
                                min={0}
                                max={365}
                                value={form.max_days_per_year}
                                onChange={(e) => set('max_days_per_year', e.target.value)}
                                error={fieldError('max_days_per_year')}
                            />
                            <p className="mt-1 text-xs text-slate-500">
                                Leave blank for unlimited. Changing this does not affect leave balances already issued.
                            </p>
                        </div>
                        <div />

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

                        <div className="sm:col-span-2 space-y-3">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_paid}
                                    onChange={(e) => set('is_paid', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                Paid
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.requires_approval}
                                    onChange={(e) => set('requires_approval', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                Requires approval
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.requires_document}
                                    onChange={(e) => set('requires_document', e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-600"
                                />
                                Requires supporting document
                            </label>
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" onClick={() => router.visit('/settings/leave-types')}>
                            Back to list
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
