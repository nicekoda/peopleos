import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { DashboardSummary } from '@/types/dashboard';
import { PageProps } from '@/types';

interface DashboardProps extends PageProps {
    linkedEmployee: { id: string; full_name: string; status: string } | null;
    permissionCount: number;
}

/**
 * Real module summary cards (Checkpoint 21), fetched client-side from
 * /api/v1/dashboard — same pattern as every other module. `dashboard.view`
 * only gates reaching that endpoint at all; every card in the response
 * was already independently permission-checked server-side before being
 * included, so this component never re-derives or hides a card based on
 * its own guess at permissions — it renders exactly what the backend
 * decided was safe to send.
 *
 * Platform Super Admins (no tenant resolved) never call this endpoint —
 * it's tenant-scoped only (Refinement 1/7) — and instead see a plain,
 * safe platform-administrator message with no fabricated tenant data.
 */
export default function Dashboard() {
    const { auth, tenant, linkedEmployee, permissionCount } = usePage<DashboardProps>().props;
    const isPlatformAdmin = auth.user?.is_platform_admin ?? false;

    const [summary, setSummary] = useState<DashboardSummary | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        if (isPlatformAdmin || !tenant) {
            return;
        }

        api.get<DashboardSummary>('/dashboard')
            .then((response) => setSummary(response.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [isPlatformAdmin, tenant]);

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <PageHeader
                title={`Welcome, ${auth.user?.name ?? 'there'}`}
                description={tenant ? tenant.name : 'Platform administration'}
            />

            {isPlatformAdmin || !tenant ? (
                <EmptyState
                    title="Platform dashboard is not available in this checkpoint"
                    description="You are signed in as a platform administrator, not scoped to any tenant. Tenant module summaries don't apply here — a dedicated platform-level dashboard is future work."
                />
            ) : (
                <>
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
                                    No linked employee record. Self-service employee features aren&apos;t available
                                    until HR links your account.
                                </p>
                            )}
                        </Card>

                        <Card title="Tenant">
                            <p className="text-sm text-slate-500">{tenant.name}</p>
                        </Card>
                    </div>

                    {error && (
                        <div className="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
                    )}

                    {summary === null && !error && (
                        <div className="mt-8">
                            <LoadingState label="Loading dashboard summary…" />
                        </div>
                    )}

                    {summary !== null && (
                        <>
                            <div className="mt-8">
                                <h2 className="mb-4 text-sm font-semibold text-slate-900">Summary</h2>
                                {summary.cards.length === 0 ? (
                                    <EmptyState
                                        title="No summary cards available"
                                        description="Your account doesn't hold any module permissions with dashboard summaries yet."
                                    />
                                ) : (
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        {summary.cards.map((card) => {
                                            const content = (
                                                <Card key={card.key}>
                                                    <p className="text-sm text-slate-500">{card.label}</p>
                                                    <p className="mt-1 text-2xl font-semibold text-slate-900">{card.value}</p>
                                                </Card>
                                            );

                                            return card.href ? (
                                                <Link key={card.key} href={card.href} className="block hover:opacity-80">
                                                    {content}
                                                </Link>
                                            ) : (
                                                content
                                            );
                                        })}
                                    </div>
                                )}
                            </div>

                            {summary.quick_links.length > 0 && (
                                <div className="mt-8">
                                    <h2 className="mb-4 text-sm font-semibold text-slate-900">Quick links</h2>
                                    <div className="flex flex-wrap gap-3">
                                        {summary.quick_links.map((link) => (
                                            <Link
                                                key={link.href}
                                                href={link.href}
                                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                            >
                                                {link.label}
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="mt-8">
                                <h2 className="mb-4 text-sm font-semibold text-slate-900">Recent activity</h2>
                                {summary.recent_items.length === 0 ? (
                                    <EmptyState title="Nothing recent" description="Recent activity you have access to will appear here." />
                                ) : (
                                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                                        <ul className="divide-y divide-slate-100">
                                            {summary.recent_items.map((item, index) => (
                                                <li key={`${item.type}-${index}`}>
                                                    <Link href={item.href} className="block px-4 py-3 text-sm hover:bg-slate-50">
                                                        {item.label}
                                                    </Link>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </>
            )}
        </AppLayout>
    );
}
