import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PaginatedResponse } from '@/types/user';
import { Role } from '@/types/role';

/**
 * Read-only role list (Checkpoint 23) — no create/edit/delete, no
 * permission list per role, only a computed count. Fetched from the
 * new, tenant-filtered /api/v1/roles endpoint — can never include a
 * platform role or another tenant's roles (Role has no BelongsToTenant
 * global scope, so the backend filters manually — see docs/security.md).
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
                    <Link href="/settings/access" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Users &amp; Access
                    </Link>
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
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Description</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {roles.map((role) => (
                                <tr key={role.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{role.name}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone="neutral">Tenant role</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{role.permission_count}</td>
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
