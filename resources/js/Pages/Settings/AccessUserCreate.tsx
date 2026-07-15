import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PaginatedResponse, User, UserCreateFormPayload } from '@/types/user';
import { Role as RoleType } from '@/types/role';
import { Employee, PaginatedResponse as EmployeePaginatedResponse } from '@/types/employee';

/**
 * Checkpoint 43 — the first user-creation page in this app. Deliberately
 * a separate, explicit action, not a step folded into candidate
 * conversion or onboarding start (your approved scope choice) — you
 * reach this page on purpose, from Settings > Access > Users or from an
 * Employee's own detail page. employeeId in the query string (from the
 * latter) only pre-selects the employee dropdown, same "URL param is a
 * pre-fill, never a trusted value" rule Lifecycle/Create.tsx already
 * follows for its own employeeId param — the actual value sent to the
 * backend is always whatever this form currently holds.
 *
 * Checkpoint 46 — send_invite defaults to true (send an invite email);
 * switching to "Set password now" reveals the password/confirm fields,
 * matching Checkpoint 43's original behavior exactly. Only one path's
 * fields are ever included in the submitted payload — never both, since
 * StoreUserRequest rejects a password submitted alongside send_invite:
 * true outright.
 */
export default function SettingsAccessUserCreate() {
    const params = useMemo(() => new URLSearchParams(window.location.search), []);

    const [form, setForm] = useState<UserCreateFormPayload>({
        name: '',
        email: '',
        send_invite: true,
        password: '',
        password_confirmation: '',
        role_id: '',
        employee_id: params.get('employeeId') ?? '',
    });
    const [roles, setRoles] = useState<RoleType[] | null>(null);
    const [employees, setEmployees] = useState<Employee[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<PaginatedResponse<RoleType>>('/roles')
            .then((response) => setRoles(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, []);

    // Includes the pre-selected employee (if any) even if it were ever
    // somehow already linked/terminated by the time this loads — the
    // dropdown can show it, but StoreUserRequest is the real guard that
    // rejects submitting it.
    useEffect(() => {
        api.get<EmployeePaginatedResponse<Employee>>('/employees')
            .then((response) => setEmployees(response.data.data.filter((employee) => !employee.linked_user && employee.status !== 'terminated')))
            .catch(() => setEmployees([]));
    }, []);

    const set = <K extends keyof UserCreateFormPayload>(key: K, value: UserCreateFormPayload[K]) => {
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
            email: form.email,
            send_invite: form.send_invite,
            role_id: Number(form.role_id),
            ...(form.send_invite ? {} : { password: form.password, password_confirmation: form.password_confirmation }),
            ...(form.employee_id ? { employee_id: form.employee_id } : {}),
        };

        api.post<{ data: User }>('/users', payload)
            .then((response) => {
                router.visit(`/settings/access/users/${response.data.data.id}`);
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
            <Head title="Create user" />

            <PageHeader
                title="Create user account"
                description="Send an invite email so the new user sets their own password, or set an initial password directly yourself."
                actions={
                    <Link href="/settings/access/users" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Users
                    </Link>
                }
            />

            {loadError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
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
                        <InputField
                            label="Email"
                            name="email"
                            type="email"
                            required
                            value={form.email}
                            onChange={(e) => set('email', e.target.value)}
                            error={fieldError('email')}
                        />
                        <SelectField
                            label="Password setup"
                            name="send_invite"
                            required
                            value={form.send_invite ? 'invite' : 'set'}
                            onChange={(e) => set('send_invite', e.target.value === 'invite')}
                        >
                            <option value="invite">Send invite email (recommended)</option>
                            <option value="set">Set password now</option>
                        </SelectField>
                        {!form.send_invite && (
                            <>
                                <InputField
                                    label="Password"
                                    name="password"
                                    type="password"
                                    required
                                    value={form.password}
                                    onChange={(e) => set('password', e.target.value)}
                                    error={fieldError('password')}
                                />
                                <InputField
                                    label="Confirm password"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    value={form.password_confirmation}
                                    onChange={(e) => set('password_confirmation', e.target.value)}
                                />
                            </>
                        )}
                        <SelectField
                            label="Role"
                            name="role_id"
                            required
                            value={form.role_id}
                            onChange={(e) => set('role_id', e.target.value)}
                            error={fieldError('role_id')}
                        >
                            <option value="">— Select a role —</option>
                            {(roles ?? []).map((role) => (
                                <option key={role.id} value={role.id}>
                                    {role.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Link to employee (optional)"
                            name="employee_id"
                            value={form.employee_id}
                            onChange={(e) => set('employee_id', e.target.value)}
                            error={fieldError('employee_id')}
                        >
                            <option value="">— Not linked —</option>
                            {(employees ?? []).map((employee) => (
                                <option key={employee.id} value={employee.id}>
                                    {employee.full_name} ({employee.employee_number})
                                </option>
                            ))}
                        </SelectField>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting || roles === null}>
                            {submitting ? 'Creating…' : 'Create user'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
