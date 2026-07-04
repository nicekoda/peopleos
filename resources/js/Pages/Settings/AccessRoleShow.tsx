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
import { Role } from '@/types/role';
import { Permission } from '@/types/permission';
import { PaginatedResponse } from '@/types/user';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    roleId: number;
}

function Field({ label, value }: { label: string; value: string | number | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Role detail + permission management (Checkpoint 28). Every mutating
 * action here (permission assign/remove) calls an existing,
 * independently-permission-gated backend endpoint and waits for a
 * confirmed success before updating the displayed state — never
 * optimistic, same rule as every other module in this app. Permission
 * assignment/removal is only ever offered for a custom
 * (is_system_role: false) role — for a system role, the entire
 * "Permissions" card renders as a safe, read-only, grouped list with no
 * add/remove actions, regardless of what permission the viewer holds
 * (the backend would reject the mutation anyway; this just avoids
 * showing a control that could never succeed).
 */
export default function SettingsAccessRoleShow() {
    const { roleId } = usePage<ShowProps>().props;
    const canAssignPermission = useCan('permissions.assign');
    const canUpdateRole = useCan('roles.update');

    const [role, setRole] = useState<Role | null>(null);
    const [roleError, setRoleError] = useState<ApiError | null>(null);

    const [allPermissions, setAllPermissions] = useState<Permission[] | null>(null);
    const [selectedPermissionId, setSelectedPermissionId] = useState('');
    const [assigning, setAssigning] = useState(false);
    const [assignError, setAssignError] = useState<string | null>(null);

    const [removingPermissionId, setRemovingPermissionId] = useState<number | null>(null);
    const [removeError, setRemoveError] = useState<string | null>(null);

    const loadRole = useCallback(() => {
        api.get<{ data: Role }>(`/roles/${roleId}`)
            .then((response) => setRole(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setRoleError(apiError);
                }
            });
    }, [roleId]);

    useEffect(() => {
        loadRole();
    }, [loadRole]);

    useEffect(() => {
        if (!canAssignPermission || role?.is_system_role) return;

        api.get<PaginatedResponse<Permission>>('/permissions')
            .then((response) => setAllPermissions(response.data.data))
            .catch(() => setAllPermissions([]));
    }, [canAssignPermission, role?.is_system_role]);

    const groupedPermissions = useMemo(() => {
        const groups = new Map<string, Permission[]>();
        for (const permission of role?.permissions ?? []) {
            const list = groups.get(permission.category) ?? [];
            list.push(permission);
            groups.set(permission.category, list);
        }
        return Array.from(groups.entries()).sort(([a], [b]) => a.localeCompare(b));
    }, [role?.permissions]);

    const assignablePermissions = useMemo(() => {
        const assignedIds = new Set(role?.permissions?.map((permission) => permission.id) ?? []);
        return (allPermissions ?? []).filter((permission) => !assignedIds.has(permission.id));
    }, [allPermissions, role?.permissions]);

    const handleAssign = () => {
        if (!selectedPermissionId) return;

        setAssigning(true);
        setAssignError(null);

        api.post<{ data: Role }>(`/roles/${roleId}/permissions`, { permission_id: Number(selectedPermissionId) })
            .then((response) => {
                setRole(response.data.data);
                setSelectedPermissionId('');
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setAssignError(apiError.errors?.permission_id?.[0] ?? apiError.message);
                }
            })
            .finally(() => setAssigning(false));
    };

    const handleRemove = (permissionId: number, permissionKey: string) => {
        if (!window.confirm(`Remove "${permissionKey}" from this role?`)) return;

        setRemovingPermissionId(permissionId);
        setRemoveError(null);

        api.delete<{ data: Role }>(`/roles/${roleId}/permissions/${permissionId}`)
            .then((response) => setRole(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setRemoveError(apiError.message);
                }
            })
            .finally(() => setRemovingPermissionId(null));
    };

    if (roleError) {
        return (
            <AppLayout>
                <Head title="Role" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{roleError.message}</div>
            </AppLayout>
        );
    }

    if (!role) {
        return (
            <AppLayout>
                <Head title="Role" />
                <LoadingState label="Loading role…" />
            </AppLayout>
        );
    }

    const canEditThisRole = canUpdateRole && !role.is_system_role;

    return (
        <AppLayout>
            <Head title={role.name} />

            <PageHeader
                title={role.name}
                description={
                    <>
                        {role.slug} ·{' '}
                        <Badge tone={role.is_system_role ? 'neutral' : 'success'}>
                            {role.is_system_role ? 'System Role' : 'Custom Role'}
                        </Badge>
                    </>
                }
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings/access/roles" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Roles
                        </Link>
                        {canEditThisRole && (
                            <Link
                                href={`/settings/access/roles/${roleId}/edit`}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Edit
                            </Link>
                        )}
                    </div>
                }
            />

            {role.is_system_role && (
                <div className="mb-4 rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    System roles are protected and cannot be edited in this checkpoint.
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Description" value={role.description} />
                        <Field label="Permissions" value={role.permission_count} />
                        <Field label="Users assigned" value={role.user_count} />
                        <Field label="Created" value={role.created_at?.slice(0, 10)} />
                    </dl>
                </Card>

                <Card title="Assign a permission">
                    {role.is_system_role ? (
                        <p className="text-sm text-slate-500">Permissions on system roles cannot be changed in this checkpoint.</p>
                    ) : (
                        <PermissionGate
                            permission="permissions.assign"
                            fallback={<p className="text-sm text-slate-500">You don&apos;t have permission to assign permissions.</p>}
                        >
                            {assignError && (
                                <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{assignError}</p>
                            )}
                            {allPermissions === null ? (
                                <LoadingState label="Loading permissions…" />
                            ) : assignablePermissions.length === 0 ? (
                                <p className="text-sm text-slate-500">This role already holds every available permission.</p>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <select
                                        value={selectedPermissionId}
                                        onChange={(e) => setSelectedPermissionId(e.target.value)}
                                        className="block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                    >
                                        <option value="">Select a permission…</option>
                                        {assignablePermissions.map((permission) => (
                                            <option key={permission.id} value={permission.id}>
                                                {permission.key}
                                            </option>
                                        ))}
                                    </select>
                                    <Button type="button" disabled={!selectedPermissionId || assigning} onClick={handleAssign}>
                                        {assigning ? 'Assigning…' : 'Assign'}
                                    </Button>
                                </div>
                            )}
                        </PermissionGate>
                    )}
                </Card>
            </div>

            <div className="mt-4">
                <Card title="Permissions by category">
                    {removeError && (
                        <p className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{removeError}</p>
                    )}

                    {groupedPermissions.length === 0 ? (
                        <p className="text-sm text-slate-500">No permissions assigned to this role.</p>
                    ) : (
                        <div className="space-y-6">
                            {groupedPermissions.map(([category, permissions]) => (
                                <div key={category}>
                                    <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{category}</h3>
                                    <ul className="divide-y divide-slate-100 rounded-md border border-slate-100">
                                        {permissions.map((permission) => (
                                            <li key={permission.id} className="flex items-center justify-between px-3 py-2 text-sm">
                                                <span className="text-slate-900">{permission.key}</span>
                                                {!role.is_system_role && (
                                                    <PermissionGate permission="permissions.assign">
                                                        <button
                                                            type="button"
                                                            disabled={removingPermissionId === permission.id}
                                                            onClick={() => handleRemove(permission.id, permission.key)}
                                                            className="text-red-600 hover:text-red-500 disabled:opacity-50"
                                                        >
                                                            {removingPermissionId === permission.id ? 'Removing…' : 'Remove'}
                                                        </button>
                                                    </PermissionGate>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
