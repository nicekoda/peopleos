import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Tenant } from '@/types/tenant';
import { PageProps } from '@/types';

interface Section {
    key: string;
    title: string;
    description: string;
    href: string;
    permission: string;
    comingLater: boolean;
}

/**
 * Every section below is gated by its own module permission
 * (Checkpoint 22) — tenant.settings.view (checked server-side in
 * SettingsController::index()) only grants reaching this page at all,
 * never any section's data. A section's card simply doesn't render if
 * the viewer lacks its permission — the same "hide, never the security
 * boundary" rule as everywhere else in this app; the destination route
 * independently re-checks the same permission.
 */
const sections: Section[] = [
    {
        key: 'company',
        title: 'Company Profile',
        description: 'Tenant name, subdomain, and status.',
        href: '/settings/company',
        permission: 'tenant.view',
        comingLater: false,
    },
    {
        key: 'access',
        title: 'Users & Access',
        description: 'Manage user accounts and access.',
        href: '/settings/access',
        permission: 'users.view',
        comingLater: false,
    },
    {
        key: 'roles',
        title: 'Roles & Permissions',
        description: 'Manage roles and permission grants.',
        href: '/settings/access',
        permission: 'roles.view',
        comingLater: false,
    },
    {
        key: 'document_categories',
        title: 'Document Categories',
        description: 'Manage the document category catalog.',
        href: '/settings/document-categories',
        permission: 'document_categories.view',
        comingLater: false,
    },
    {
        key: 'hr_document_templates',
        title: 'HR Document Templates',
        description: 'Manage templates for HR letter and document generation.',
        href: '/settings/hr-document-templates',
        permission: 'hr_document_templates.view',
        comingLater: false,
    },
    {
        key: 'leave_types',
        title: 'Leave Types',
        description: 'Manage leave types and entitlements.',
        href: '/settings/leave-types',
        permission: 'leave_types.view',
        comingLater: false,
    },
    {
        key: 'departments',
        title: 'Departments',
        description: 'Manage the department catalog.',
        href: '/settings/departments',
        permission: 'departments.view',
        comingLater: false,
    },
    {
        key: 'positions',
        title: 'Positions',
        description: 'Manage job titles used across employee records.',
        href: '/settings/positions',
        permission: 'positions.view',
        comingLater: false,
    },
    {
        key: 'locations',
        title: 'Locations',
        description: 'Manage the office/location catalog.',
        href: '/settings/locations',
        permission: 'locations.view',
        comingLater: false,
    },
    {
        key: 'lifecycle_task_templates',
        title: 'Onboarding & Offboarding Task Templates',
        description: 'Manage default tasks automatically added to newly started processes.',
        href: '/settings/lifecycle-task-templates',
        permission: 'lifecycle_task_templates.view',
        comingLater: false,
    },
    {
        key: 'modules',
        title: 'Modules',
        description: 'Enable or disable optional modules for your organisation.',
        href: '/settings/modules',
        permission: 'tenant.modules.view',
        comingLater: false,
    },
    {
        key: 'branding',
        title: 'Branding',
        description: 'Logo and brand colors used across the app.',
        href: '/settings/branding',
        permission: 'tenant.branding.view',
        comingLater: false,
    },
    {
        key: 'custom_fields',
        title: 'Custom Fields',
        description: 'Manage tenant-defined fields for recruitment applicants, job applications, and employees.',
        href: '/settings/custom-fields',
        permission: 'custom_fields.view',
        comingLater: false,
    },
    {
        key: 'custom_forms',
        title: 'Custom Forms',
        description: 'Group existing custom fields into sections for display on an entity’s own page.',
        href: '/settings/custom-forms',
        permission: 'custom_forms.view',
        comingLater: false,
    },
    {
        key: 'security',
        title: 'Security & Audit',
        description: 'Review security and audit settings.',
        href: '/settings/security',
        permission: 'audit.view',
        comingLater: false,
    },
    {
        key: 'integrations',
        title: 'Integrations',
        description: 'Connect PeopleOS with other systems.',
        href: '/settings/integrations',
        permission: 'tenant.settings.view',
        comingLater: true,
    },
];

function SectionCard({ section }: { section: Section }) {
    const can = useCan(section.permission);

    if (!can) {
        return null;
    }

    return (
        <Link href={section.href} className="block hover:opacity-80">
            <Card>
                <div className="flex items-start justify-between gap-2">
                    <p className="font-medium text-slate-900">{section.title}</p>
                    {section.comingLater && <Badge tone="neutral">Coming later</Badge>}
                </div>
                <p className="mt-1 text-sm text-slate-500">{section.description}</p>
            </Card>
        </Link>
    );
}

/**
 * Settings landing page (Checkpoint 22). No raw permission arrays,
 * internal role records, or audit internals are ever rendered here —
 * only section titles/descriptions and (if tenant.view is held) a
 * small safe tenant summary (name/subdomain/status).
 */
export default function SettingsIndex() {
    const { auth, tenant } = usePage<PageProps>().props;
    const canViewTenant = useCan('tenant.view');
    const permissions = auth.user?.permissions ?? [];
    const hasAnySection = sections.some((section) => permissions.includes(section.permission));

    const [tenantSummary, setTenantSummary] = useState<Tenant | null>(null);
    const [tenantError, setTenantError] = useState<ApiError | null>(null);

    useEffect(() => {
        if (!canViewTenant) {
            return;
        }

        api.get<{ data: Tenant }>('/tenant')
            .then((response) => setTenantSummary(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setTenantError(apiError);
                }
            });
    }, [canViewTenant]);

    return (
        <AppLayout>
            <Head title="Settings" />

            <PageHeader title="Settings" description="Tenant and account settings" />

            <Card title="Company" className="mb-6">
                {tenantSummary ? (
                    <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-slate-500">Name</dt>
                            <dd className="font-medium text-slate-900">{tenantSummary.name}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Subdomain</dt>
                            <dd className="font-medium text-slate-900">{tenantSummary.subdomain}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Status</dt>
                            <dd>
                                <Badge tone={tenantSummary.status === 'active' ? 'success' : 'warning'}>{tenantSummary.status}</Badge>
                            </dd>
                        </div>
                    </dl>
                ) : tenantError ? (
                    <p className="text-sm text-red-700">{tenantError.message}</p>
                ) : (
                    <p className="text-sm text-slate-500">{tenant?.name ?? 'Loading…'}</p>
                )}
            </Card>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {sections.map((section) => (
                    <SectionCard key={section.key} section={section} />
                ))}

                {/* Static, unlinked — no destination and no data exist for this
                    section yet; a real route would be a broken link. */}
                <Card>
                    <div className="flex items-start justify-between gap-2">
                        <p className="font-medium text-slate-900">Billing &amp; Subscription</p>
                        <Badge tone="neutral">Coming later</Badge>
                    </div>
                    <p className="mt-1 text-sm text-slate-500">Manage your subscription plan and billing details.</p>
                </Card>
            </div>

            {!hasAnySection && (
                <div className="mt-8">
                    <EmptyState
                        title="No settings sections available"
                        description="Your account doesn't hold any settings-related permissions yet."
                    />
                </div>
            )}
        </AppLayout>
    );
}
