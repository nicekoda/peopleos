import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Role, RoleFormPayload } from '@/types/role';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    roleId: number;
}

/**
 * Checkpoint 28 — name/description only, and only ever for a custom
 * (is_system_role: false) role. The "System roles are protected"
 * message below is purely informational — this page never submits an
 * edit for a system role in the first place (no form is rendered), and
 * RoleController::update() independently rejects it (403) regardless
 * of what this page does. Slug is shown read-only, never editable.
 */
export default function SettingsAccessRoleEdit() {
    const { roleId } = usePage<EditProps>().props;

    const [role, setRole] = useState<Role | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<RoleFormPayload | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<{ data: Role }>(`/roles/${roleId}`)
            .then((response) => {
                setRole(response.data.data);
                setForm({
                    name: response.data.data.name,
                    description: response.data.data.description ?? '',
                });
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [roleId]);

    const set = <K extends keyof RoleFormPayload>(key: K, value: RoleFormPayload[K]) => {
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
        };

        api.patch<{ data: Role }>(`/roles/${roleId}`, payload)
            .then(() => setSuccessMessage('Role updated.'))
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
                <Head title="Edit role" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!role || !form) {
        return (
            <AppLayout>
                <Head title="Edit role" />
                <LoadingState label="Loading role…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={`Edit ${role.name}`} />

            <PageHeader
                title={`Edit ${role.name}`}
                actions={
                    <Link href={`/settings/access/roles/${roleId}`} className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Role
                    </Link>
                }
            />

            {role.is_system_role ? (
                <Card>
                    <p className="text-sm text-slate-700">
                        System roles are protected and cannot be edited in this checkpoint.
                    </p>
                </Card>
            ) : (
                <>
                    {successMessage && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
                    )}
                    {generalError && (
                        <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
                    )}

                    <form onSubmit={submit}>
                        <Card>
                            <div className="grid grid-cols-1 gap-4">
                                <InputField
                                    label="Name"
                                    name="name"
                                    required
                                    value={form.name}
                                    onChange={(e) => set('name', e.target.value)}
                                    error={fieldError('name')}
                                />

                                <div>
                                    <span className="block text-sm font-medium text-slate-700">Slug</span>
                                    <p className="mt-1.5 text-sm text-slate-500">{role.slug} (not editable)</p>
                                </div>

                                <div>
                                    <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                        Description
                                    </label>
                                    <textarea
                                        id="description"
                                        rows={3}
                                        value={form.description ?? ''}
                                        onChange={(e) => set('description', e.target.value)}
                                        className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    />
                                    <ErrorMessage message={fieldError('description')} />
                                </div>
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <Button type="button" variant="secondary" onClick={() => router.visit(`/settings/access/roles/${roleId}`)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={submitting}>
                                    {submitting ? 'Saving…' : 'Save changes'}
                                </Button>
                            </div>
                        </Card>
                    </form>
                </>
            )}
        </AppLayout>
    );
}
