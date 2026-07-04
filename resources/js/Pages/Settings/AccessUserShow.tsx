import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { User, UserStatus } from '@/types/user';
import { Role } from '@/types/role';
import { Employee, PaginatedResponse } from '@/types/employee';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    userId: number;
}

const statusTone: Record<UserStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
    suspended: 'danger',
};

const STATUSES: UserStatus[] = ['active', 'inactive', 'suspended'];

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * The most security-sensitive page in this checkpoint. Every action
 * here (status change, role assign/remove, employee link/unlink) calls
 * an existing, independently-permission-gated backend endpoint and
 * waits for a confirmed success before updating the displayed state —
 * never optimistic. A 409 (e.g. "last Tenant Admin" protection) is
 * shown as a plain safe message, never treated as a bug to route
 * around. See docs/security.md.
 */
export default function SettingsAccessUserShow() {
    const { userId } = usePage<ShowProps>().props;
    const canAssignRole = useCan('users.assign_role');
    const canLinkUser = useCan('employees.link_user');

    const [user, setUser] = useState<User | null>(null);
    const [userError, setUserError] = useState<ApiError | null>(null);

    const [roles, setRoles] = useState<Role[] | null>(null);
    const [employees, setEmployees] = useState<Employee[] | null>(null);

    const [selectedStatus, setSelectedStatus] = useState<UserStatus>('active');
    const [statusSubmitting, setStatusSubmitting] = useState(false);
    const [statusError, setStatusError] = useState<string | null>(null);
    const [statusSuccess, setStatusSuccess] = useState<string | null>(null);

    const [selectedRoleId, setSelectedRoleId] = useState('');
    const [assigning, setAssigning] = useState(false);
    const [assignError, setAssignError] = useState<string | null>(null);

    const [removingRoleId, setRemovingRoleId] = useState<number | null>(null);
    const [removeError, setRemoveError] = useState<string | null>(null);

    const [selectedEmployeeId, setSelectedEmployeeId] = useState('');
    const [linking, setLinking] = useState(false);
    const [linkError, setLinkError] = useState<string | null>(null);

    const [unlinking, setUnlinking] = useState(false);
    const [unlinkError, setUnlinkError] = useState<string | null>(null);

    const loadUser = useCallback(() => {
        api.get<{ data: User }>(`/users/${userId}`)
            .then((response) => {
                setUser(response.data.data);
                setSelectedStatus(response.data.data.status);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setUserError(apiError);
                }
            });
    }, [userId]);

    useEffect(() => {
        loadUser();
    }, [loadUser]);

    useEffect(() => {
        if (!canAssignRole) return;

        api.get<PaginatedResponse<Role>>('/roles')
            .then((response) => setRoles(response.data.data))
            .catch(() => setRoles([]));
    }, [canAssignRole]);

    useEffect(() => {
        if (!canLinkUser || user?.linked_employee) return;

        api.get<PaginatedResponse<Employee>>('/employees')
            .then((response) => setEmployees(response.data.data.filter((employee) => employee.status !== 'terminated')))
            .catch(() => setEmployees([]));
    }, [canLinkUser, user?.linked_employee]);

    const assignableRoles = useMemo(() => {
        const assignedIds = new Set(user?.roles.map((role) => role.id) ?? []);
        return (roles ?? []).filter((role) => !assignedIds.has(role.id));
    }, [roles, user]);

    const handleStatusChange = () => {
        if (!user || selectedStatus === user.status) return;
        if (!window.confirm(`Change status to "${selectedStatus}"?`)) return;

        setStatusSubmitting(true);
        setStatusError(null);
        setStatusSuccess(null);

        api.patch<{ data: User }>(`/users/${userId}`, { status: selectedStatus })
            .then((response) => {
                setUser(response.data.data);
                setStatusSuccess('Status updated.');
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setStatusError(apiError.message);
                    setSelectedStatus(user.status);
                }
            })
            .finally(() => setStatusSubmitting(false));
    };

    const handleAssignRole = () => {
        if (!selectedRoleId) return;

        setAssigning(true);
        setAssignError(null);

        api.post<{ data: User }>(`/users/${userId}/roles`, { role_id: Number(selectedRoleId) })
            .then((response) => {
                setUser(response.data.data);
                setSelectedRoleId('');
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setAssignError(apiError.errors?.role_id?.[0] ?? apiError.message);
                }
            })
            .finally(() => setAssigning(false));
    };

    const handleRemoveRole = (roleId: number, roleName: string) => {
        if (!window.confirm(`Remove the "${roleName}" role from this user?`)) return;

        setRemovingRoleId(roleId);
        setRemoveError(null);

        api.delete<{ data: User }>(`/users/${userId}/roles/${roleId}`)
            .then((response) => setUser(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setRemoveError(apiError.message);
                }
            })
            .finally(() => setRemovingRoleId(null));
    };

    const handleLink = () => {
        if (!selectedEmployeeId) return;

        setLinking(true);
        setLinkError(null);

        api.post(`/employees/${selectedEmployeeId}/link-user`, { user_id: userId })
            .then(() => loadUser())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLinkError(apiError.errors?.user_id?.[0] ?? apiError.message);
                }
            })
            .finally(() => setLinking(false));
    };

    const handleUnlink = () => {
        if (!user?.linked_employee) return;
        if (!window.confirm(`Unlink ${user.linked_employee.full_name} from this user account?`)) return;

        setUnlinking(true);
        setUnlinkError(null);

        api.delete(`/employees/${user.linked_employee.id}/unlink-user`)
            .then(() => loadUser())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setUnlinkError(apiError.message);
                }
            })
            .finally(() => setUnlinking(false));
    };

    if (userError) {
        return (
            <AppLayout>
                <Head title="User" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{userError.message}</div>
            </AppLayout>
        );
    }

    if (!user) {
        return (
            <AppLayout>
                <Head title="User" />
                <LoadingState label="Loading user…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={user.name} />

            <PageHeader
                title={user.name}
                description={
                    <>
                        {user.email} · <Badge tone={statusTone[user.status]}>{user.status}</Badge>
                    </>
                }
                actions={
                    <Link href="/settings/access/users" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Users
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Type" value="Tenant user" />
                        <Field label="Last login" value={user.last_login_at?.slice(0, 10)} />
                        <Field label="Created" value={user.created_at?.slice(0, 10)} />
                    </dl>
                </Card>

                <PermissionGate permission="users.deactivate">
                    <Card title="Status">
                        {statusSuccess && (
                            <p className="mb-3 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{statusSuccess}</p>
                        )}
                        {statusError && (
                            <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{statusError}</p>
                        )}
                        <div className="flex items-center gap-3">
                            <select
                                value={selectedStatus}
                                onChange={(e) => setSelectedStatus(e.target.value as UserStatus)}
                                className="block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            >
                                {STATUSES.map((status) => (
                                    <option key={status} value={status}>
                                        {status}
                                    </option>
                                ))}
                            </select>
                            <Button
                                type="button"
                                disabled={statusSubmitting || selectedStatus === user.status}
                                onClick={handleStatusChange}
                            >
                                {statusSubmitting ? 'Saving…' : 'Update'}
                            </Button>
                        </div>
                    </Card>
                </PermissionGate>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Roles">
                    {user.roles.length === 0 && <p className="text-sm text-slate-500">No roles assigned.</p>}
                    {user.roles.length > 0 && (
                        <ul className="divide-y divide-slate-100">
                            {user.roles.map((role) => (
                                <li key={role.id} className="flex items-center justify-between py-2 text-sm">
                                    <span className="font-medium text-slate-900">{role.name}</span>
                                    <PermissionGate permission="users.assign_role">
                                        <button
                                            type="button"
                                            disabled={removingRoleId === role.id}
                                            onClick={() => handleRemoveRole(role.id, role.name)}
                                            className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                        >
                                            {removingRoleId === role.id ? 'Removing…' : 'Remove'}
                                        </button>
                                    </PermissionGate>
                                </li>
                            ))}
                        </ul>
                    )}

                    {removeError && (
                        <p className="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{removeError}</p>
                    )}

                    <PermissionGate permission="users.assign_role">
                        <div className="mt-4 border-t border-slate-100 pt-4">
                            {assignError && (
                                <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{assignError}</p>
                            )}
                            {assignableRoles.length === 0 ? (
                                <p className="text-sm text-slate-500">No further roles available to assign.</p>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <select
                                        value={selectedRoleId}
                                        onChange={(e) => setSelectedRoleId(e.target.value)}
                                        className="block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    >
                                        <option value="">Select a role…</option>
                                        {assignableRoles.map((role) => (
                                            <option key={role.id} value={role.id}>
                                                {role.name}
                                            </option>
                                        ))}
                                    </select>
                                    <Button type="button" disabled={!selectedRoleId || assigning} onClick={handleAssignRole}>
                                        {assigning ? 'Assigning…' : 'Assign'}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </PermissionGate>
                </Card>

                <Card title="Linked employee">
                    {linkError && (
                        <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{linkError}</p>
                    )}
                    {unlinkError && (
                        <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{unlinkError}</p>
                    )}

                    {user.linked_employee ? (
                        <div className="flex items-center justify-between">
                            <p className="text-sm font-medium text-slate-900">{user.linked_employee.full_name}</p>
                            <PermissionGate permission="employees.unlink_user">
                                <Button type="button" variant="secondary" disabled={unlinking} onClick={handleUnlink}>
                                    {unlinking ? 'Unlinking…' : 'Unlink'}
                                </Button>
                            </PermissionGate>
                        </div>
                    ) : (
                        <PermissionGate
                            permission="employees.link_user"
                            fallback={<p className="text-sm text-slate-500">Not linked to an employee record.</p>}
                        >
                            {employees === null ? (
                                <LoadingState label="Loading employees…" />
                            ) : employees.length === 0 ? (
                                <p className="text-sm text-slate-500">No employees available to link.</p>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <select
                                        value={selectedEmployeeId}
                                        onChange={(e) => setSelectedEmployeeId(e.target.value)}
                                        className="block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    >
                                        <option value="">Select an employee…</option>
                                        {employees.map((employee) => (
                                            <option key={employee.id} value={employee.id}>
                                                {employee.full_name} ({employee.employee_number})
                                            </option>
                                        ))}
                                    </select>
                                    <Button type="button" disabled={!selectedEmployeeId || linking} onClick={handleLink}>
                                        {linking ? 'Linking…' : 'Link'}
                                    </Button>
                                </div>
                            )}
                        </PermissionGate>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
