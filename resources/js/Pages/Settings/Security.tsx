import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 22) — reachable with audit.view. No
 * audit log viewing endpoint exists yet (audit logging is write-only —
 * see docs/security.md), so nothing is fetched or shown here. A real
 * audit UI is explicitly out of scope this checkpoint.
 */
export default function SettingsSecurity() {
    return (
        <AppLayout>
            <Head title="Security & Audit" />
            <PageHeader
                title="Security & Audit"
                description="Review security settings and audit activity."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />
            <EmptyState
                title="Security and audit review is coming later"
                description="This section will let you review audit log activity and security-related settings for this tenant."
            />
        </AppLayout>
    );
}
