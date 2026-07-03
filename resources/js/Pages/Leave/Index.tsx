import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 16) — see Pages/Employees/Index.tsx for
 * the rationale. Reachable only with leave.view.
 */
export default function LeaveIndex() {
    return (
        <AppLayout>
            <Head title="Leave" />
            <PageHeader title="Leave" description="Leave requests and balances" />
            <EmptyState
                title="Leave management is coming soon"
                description="This module's full interface will be built in a future checkpoint."
            />
        </AppLayout>
    );
}
