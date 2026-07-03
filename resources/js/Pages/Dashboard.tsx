import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import { PageProps } from '@/types';

interface DashboardProps extends PageProps {
    linkedEmployee: { id: string; full_name: string; status: string } | null;
    permissionCount: number;
}

/**
 * Deliberately simple this checkpoint (Checkpoint 16) — welcome
 * message, linked-employee status, and a permission-count summary.
 * Real analytics/charts are explicitly out of scope; the module cards
 * below are placeholders for future checkpoints, not live data.
 */
export default function Dashboard() {
    const { auth, tenant, linkedEmployee, permissionCount } = usePage<DashboardProps>().props;

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <PageHeader
                title={`Welcome, ${auth.user?.name ?? 'there'}`}
                description={tenant ? `${tenant.name}` : 'Platform administration'}
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Card title="Your account">
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Name</dt>
                            <dd className="font-medium text-slate-900">{auth.user?.name}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Email</dt>
                            <dd className="font-medium text-slate-900">{auth.user?.email}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-slate-500">Permissions</dt>
                            <dd className="font-medium text-slate-900">{permissionCount}</dd>
                        </div>
                    </dl>
                </Card>

                <Card title="Linked employee record">
                    {linkedEmployee ? (
                        <div className="space-y-2 text-sm">
                            <p className="font-medium text-slate-900">{linkedEmployee.full_name}</p>
                            <Badge tone={linkedEmployee.status === 'active' ? 'success' : 'neutral'}>
                                {linkedEmployee.status}
                            </Badge>
                        </div>
                    ) : (
                        <p className="text-sm text-slate-500">
                            No linked employee record. Self-service employee features aren&apos;t available until
                            HR links your account.
                        </p>
                    )}
                </Card>

                <Card title="Tenant">
                    <p className="text-sm text-slate-500">
                        {tenant ? tenant.name : 'You are signed in as a platform administrator, not scoped to a tenant.'}
                    </p>
                </Card>
            </div>

            <div className="mt-8">
                <h2 className="mb-4 text-sm font-semibold text-slate-900">Coming soon</h2>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {['Team overview', 'Leave calendar', 'Compliance summary'].map((label) => (
                        <div key={label}>
                            <EmptyState title={label} description="This module will be built in a future checkpoint." />
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
