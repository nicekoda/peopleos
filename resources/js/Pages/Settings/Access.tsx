import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 22) — reachable with users.view. Serves
 * as the destination for both the "Users & Access" and "Roles &
 * Permissions" cards on the Settings landing page (no dedicated
 * /settings/roles route exists yet). No user/role data is fetched or
 * shown — full user/RBAC management UI is explicitly out of scope this
 * checkpoint.
 */
export default function SettingsAccess() {
    return (
        <AppLayout>
            <Head title="Users & Access" />
            <PageHeader
                title="Users & Access"
                description="Manage user accounts, roles, and permission grants."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />
            <EmptyState
                title="User and role management is coming later"
                description="This section will let you invite users, assign roles, and manage direct permission grants."
            />
        </AppLayout>
    );
}
