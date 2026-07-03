import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';

/**
 * Placeholder only (Checkpoint 22) — reachable with tenant.settings.view,
 * the same umbrella permission as the Settings landing page itself. No
 * dedicated "integrations.*" permission exists, and none is invented for
 * a page with no real content yet.
 */
export default function SettingsIntegrations() {
    return (
        <AppLayout>
            <Head title="Integrations" />
            <PageHeader
                title="Integrations"
                description="Connect PeopleOS with other systems."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />
            <EmptyState
                title="Integrations are coming later"
                description="This section will let you connect PeopleOS to external systems."
            />
        </AppLayout>
    );
}
