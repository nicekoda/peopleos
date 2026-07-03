import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 16) — see Pages/Employees/Index.tsx for
 * the rationale. Reachable only with employees.update (a stand-in
 * admin-capability signal — no dedicated settings.* permission exists
 * yet, see routes/web.php).
 */
export default function SettingsIndex() {
    return (
        <AppLayout>
            <Head title="Settings" />
            <PageHeader title="Settings" description="Tenant and account settings" />
            <EmptyState
                title="Settings is coming soon"
                description="This module's full interface will be built in a future checkpoint."
            />
        </AppLayout>
    );
}
