import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import PermissionGate from '@/Components/PermissionGate';

/**
 * Real hub page (Checkpoint 24) — replaces the Checkpoint 22
 * placeholder. Links to the real, read-only Audit Logs list; no other
 * security settings exist yet, so this stays a single-card hub for now.
 */
export default function SettingsSecurity() {
    return (
        <AppLayout>
            <Head title="Security & Audit" />
            <PageHeader
                title="Security & Audit"
                description="Review audit log activity for this tenant."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <PermissionGate permission="audit.view">
                    <Link href="/settings/security/audit-logs" className="block hover:opacity-80">
                        <Card>
                            <p className="font-medium text-slate-900">Audit Logs</p>
                            <p className="mt-1 text-sm text-slate-500">
                                View a read-only history of security-relevant activity in this tenant.
                            </p>
                        </Card>
                    </Link>
                </PermissionGate>
            </div>
        </AppLayout>
    );
}
