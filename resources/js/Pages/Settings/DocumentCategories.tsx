import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 22) — reachable with document_categories.view.
 * The full document category management API already exists (Checkpoint 9)
 * but has no admin UI yet; that's explicitly out of scope this checkpoint.
 */
export default function SettingsDocumentCategories() {
    return (
        <AppLayout>
            <Head title="Document Categories" />
            <PageHeader
                title="Document Categories"
                description="Manage the document category catalog."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />
            <EmptyState
                title="Document category management is coming later"
                description="This section will let you create, rename, and archive document categories used across employee document uploads."
            />
        </AppLayout>
    );
}
