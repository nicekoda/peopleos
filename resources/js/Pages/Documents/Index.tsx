import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 16) — see Pages/Employees/Index.tsx for
 * the rationale. Reachable only with documents.view.
 */
export default function DocumentsIndex() {
    return (
        <AppLayout>
            <Head title="Documents" />
            <PageHeader title="Documents" description="Employee document repository" />
            <EmptyState
                title="Document management is coming soon"
                description="This module's full interface will be built in a future checkpoint."
            />
        </AppLayout>
    );
}
