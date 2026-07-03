import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 16) — see Pages/Employees/Index.tsx for
 * the rationale. Reachable only with policies.view.
 */
export default function PoliciesIndex() {
    return (
        <AppLayout>
            <Head title="Policies" />
            <PageHeader title="Policies" description="Company policies and acknowledgements" />
            <EmptyState
                title="Policy management is coming soon"
                description="This module's full interface will be built in a future checkpoint."
            />
        </AppLayout>
    );
}
