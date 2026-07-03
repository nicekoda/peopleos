import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 22) — reachable with leave_types.view.
 * The full leave type management API already exists (Checkpoint 12)
 * but has no admin UI yet; that's explicitly out of scope this
 * checkpoint.
 */
export default function SettingsLeaveTypes() {
    return (
        <AppLayout>
            <Head title="Leave Types" />
            <PageHeader
                title="Leave Types"
                description="Manage leave types and entitlements."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />
            <EmptyState
                title="Leave type management is coming later"
                description="This section will let you create and edit leave types and their default entitlements."
            />
        </AppLayout>
    );
}
