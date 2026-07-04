import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PaginatedResponse, User, UserStatus } from '@/types/user';

const statusTone: Record<UserStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    active: 'success',
    inactive: 'neutral',
    suspended: 'danger',
};

/**
 * User data is fetched client-side from the new, tenant-filtered
 * /api/v1/users endpoint (Checkpoint 23) — this list can never include
 * a Platform Super Admin or another tenant's users, since the backend
 * manually filters both out (User has no BelongsToTenant global scope
 * to rely on — see docs/security.md). No status/role actions live here;
 * those are on the detail page.
 */
export default function SettingsAccessUsers() {
    const [users, setUsers] = useState<User[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<User>>('/users')
            .then((response) => setUsers(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <AppLayout>
            <Head title="Users" />

            <PageHeader
                title="Users"
                description="Tenant user accounts"
                actions={
                    <Link href="/settings/access" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Users &amp; Access
                    </Link>
                }
            />

            {error && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            )}

            {users === null && !error && <LoadingState label="Loading users…" />}

            {users !== null && users.length === 0 && (
                <EmptyState title="No users yet" description="Users created for this tenant will appear here." />
            )}

            {users !== null && users.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Name</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Email</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Linked employee</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Roles</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Last login</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {users.map((user) => (
                                <tr key={user.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link href={`/settings/access/users/${user.id}`} className="hover:text-indigo-600">
                                            {user.name}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">{user.email}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[user.status]}>{user.status}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {user.linked_employee?.full_name ?? 'Not linked'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {user.roles.length > 0 ? user.roles.map((role) => role.name).join(', ') : '—'}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-500">
                                        {user.last_login_at?.slice(0, 10) ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
