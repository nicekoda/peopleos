import { Head, Link } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Tenant } from '@/types/tenant';

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Only `name` is editable (Refinement 2) — subdomain/status are shown
 * read-only and are never fields in the edit form at all.
 * UpdateTenantRequest independently enforces the same rule server-side
 * regardless of what this form does or doesn't submit.
 */
export default function SettingsCompany() {
    const [tenant, setTenant] = useState<Tenant | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);

    const [editing, setEditing] = useState(false);
    const [name, setName] = useState('');
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const load = () => {
        api.get<{ data: Tenant }>('/tenant')
            .then((response) => {
                setTenant(response.data.data);
                setName(response.data.data.name);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    };

    useEffect(load, []);

    const startEditing = () => {
        setName(tenant?.name ?? '');
        setErrors({});
        setGeneralError(null);
        setSuccessMessage(null);
        setEditing(true);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        api.patch<{ data: Tenant }>('/tenant', { name })
            .then((response) => {
                setTenant(response.data.data);
                setEditing(false);
                setSuccessMessage('Company name updated.');
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
                <Head title="Company Profile" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!tenant) {
        return (
            <AppLayout>
                <Head title="Company Profile" />
                <LoadingState label="Loading company profile…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Company Profile" />

            <PageHeader
                title="Company Profile"
                description="Tenant details for this organisation."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <Card>
                {editing ? (
                    <form onSubmit={submit}>
                        <InputField
                            label="Name"
                            name="name"
                            required
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            error={errors.name?.[0]}
                        />
                        <div className="mt-4 flex justify-end gap-3">
                            <Button type="button" variant="secondary" onClick={() => setEditing(false)} disabled={submitting}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Saving…' : 'Save changes'}
                            </Button>
                        </div>
                    </form>
                ) : (
                    <>
                        <dl className="divide-y divide-slate-100">
                            <Field label="Name" value={tenant.name} />
                            <Field label="Subdomain" value={tenant.subdomain} />
                            <div className="flex justify-between py-2 text-sm">
                                <dt className="text-slate-500">Status</dt>
                                <dd>
                                    <Badge tone={tenant.status === 'active' ? 'success' : 'warning'}>{tenant.status}</Badge>
                                </dd>
                            </div>
                            <Field label="Created" value={tenant.created_at?.slice(0, 10)} />
                            <Field label="Updated" value={tenant.updated_at?.slice(0, 10)} />
                        </dl>

                        <PermissionGate permission="tenant.update">
                            <div className="mt-4 flex justify-end">
                                <Button type="button" variant="secondary" onClick={startEditing}>
                                    Edit name
                                </Button>
                            </div>
                        </PermissionGate>
                    </>
                )}
            </Card>
        </AppLayout>
    );
}
