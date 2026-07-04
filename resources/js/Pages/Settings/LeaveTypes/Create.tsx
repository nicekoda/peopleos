import { Head, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { LeaveType } from '@/types/leaveType';

interface CreateForm {
    name: string;
    description: string;
    is_paid: boolean;
    requires_approval: boolean;
    requires_document: boolean;
    max_days_per_year: string;
}

/**
 * Payload is built field-by-field, never by spreading a broader object
 * — tenant_id/created_by/updated_by/deleted_at/slug/status are never
 * fields on this form (Refinement 3). A blank `max_days_per_year` is
 * simply omitted here (Refinement 4) — for a brand-new leave type
 * there's no existing value to preserve, and the backend's own column
 * default already means "omitted" = unlimited, same end result as
 * sending an explicit null.
 */
export default function SettingsLeaveTypeCreate() {
    const [form, setForm] = useState<CreateForm>({
        name: '',
        description: '',
        is_paid: true,
        requires_approval: true,
        requires_document: false,
        max_days_per_year: '',
    });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const set = <K extends keyof CreateForm>(key: K, value: CreateForm[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            name: form.name,
            ...(form.description ? { description: form.description } : {}),
            is_paid: form.is_paid,
            requires_approval: form.requires_approval,
            requires_document: form.requires_document,
            ...(form.max_days_per_year !== '' ? { max_days_per_year: Number(form.max_days_per_year) } : {}),
        };

        api.post<{ data: LeaveType }>('/leave-types', payload)
            .then((response) => {
                router.visit(`/settings/leave-types/${response.data.data.id}/edit`);
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

    return (
        <AppLayout>
            <Head title="Create leave type" />

            <PageHeader title="Create leave type" description="New leave types start active." />

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
                        <p className="-mt-2 text-xs text-slate-500 sm:col-span-2">
                            Leave blank for unlimited — no balance will be enforced for this leave type.
                        </p>

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
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create leave type'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
