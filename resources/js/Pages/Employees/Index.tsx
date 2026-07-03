import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 16) — the real Employee Records UI is a
 * future checkpoint. Deliberately does not call any employee API; this
 * page exists to prove the permission-gated route/nav pattern works,
 * not to show real data yet. Reachable only with employees.view (see
 * routes/web.php) — a direct-link visitor without it is rejected by
 * the backend before this component ever renders.
 */
export default function EmployeesIndex() {
    return (
        <AppLayout>
            <Head title="Employees" />
            <PageHeader title="Employees" description="Employee records" />
            <EmptyState
                title="Employee management is coming soon"
                description="This module's full interface will be built in a future checkpoint."
            />
        </AppLayout>
    );
}
