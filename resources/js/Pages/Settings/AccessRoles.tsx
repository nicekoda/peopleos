import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PaginatedResponse } from '@/types/user';
import { Role } from '@/types/role';

/**
 * Role list (Checkpoint 23; create action + built-in/custom badge added
 * Checkpoint 28) — no raw permission list per role here, only a
 * computed count; the full grouped permission list lives on the detail
 * page. Fetched from the tenant-filtered /api/v1/roles endpoint — can
 * never include a platform role or another tenant's roles (Role has no
 * BelongsToTenant global scope, so the backend filters manually — see
 * docs/security.md).
 */
export default function SettingsAccessRoles() {
    const [roles, setRoles] = useState<Role[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        api.get<PaginatedResponse<Role>>('/roles')
            .then((response) => setRoles(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    return (
        <AppLayout>
            <Head title="Roles" />

            <PageHeader
                title="Roles"
                description="Tenant roles and how many permissions each holds."
                actions={
                    <div className="flex items-center gap-3">
                        <Link href="/settings/access" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                            Back to Users &amp; Access
                        </Link>
                        <PermissionGate permission="roles.create">
                            <Link
                                href="/settings/access/roles/create"
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Create Role
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {roles === null && !error && <LoadingState label="Loading roles…" />}

            {roles !== null && roles.length === 0 && (
                <EmptyState title="No roles yet" description="Roles created for this tenant will appear here." />
            )}

            {roles !== null && roles.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Name</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Type</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Permissions</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Users</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {roles.map((role) => (
                                <tr key={role.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link href={`/settings/access/roles/${role.id}`} className="text-indigo-600 hover:text-indigo-500">
                                            {role.name}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={role.is_system_role ? 'neutral' : 'success'}>
                                            {role.is_system_role ? 'System Role' : 'Custom Role'}
                                        </Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{role.permission_count}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{role.user_count ?? '—'}</td>
                                    <td className="px-4 py-3 text-slate-500">{role.description ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
