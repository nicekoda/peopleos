import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import PermissionGate from '@/Components/PermissionGate';

/**
 * Real hub page (Checkpoint 23) — replaces the Checkpoint 22
 * placeholder. Cards link to the real Users and Roles list pages, each
 * independently gated by its own permission — no invitation flow, no
 * RBAC editing, no direct permission grants this checkpoint.
 */
export default function SettingsAccess() {
    return (
        <AppLayout>
            <Head title="Users & Access" />
            <PageHeader
                title="Users & Access"
                description="Manage user accounts and view roles."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <PermissionGate permission="users.view">
                    <Link href="/settings/access/users" className="block hover:opacity-80">
                        <Card>
                            <p className="font-medium text-slate-900">Users</p>
                            <p className="mt-1 text-sm text-slate-500">
                                View user accounts, manage status, and assign roles.
                            </p>
                        </Card>
                    </Link>
                </PermissionGate>

                <PermissionGate permission="roles.view">
                    <Link href="/settings/access/roles" className="block hover:opacity-80">
                        <Card>
                            <p className="font-medium text-slate-900">Roles</p>
                            <p className="mt-1 text-sm text-slate-500">
                                View roles and how many permissions each one holds.
                            </p>
                        </Card>
                    </Link>
                </PermissionGate>
            </div>
        </AppLayout>
    );
}
