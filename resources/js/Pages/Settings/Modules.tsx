import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { TenantModuleState } from '@/types/tenantModule';

/**
 * Checkpoint 47 — Tenant Admin can toggle modules (tenant.modules.manage);
 * HR Director/HR Manager (tenant.modules.view only) see the same list
 * read-only. Disabling a module never deletes/mutates its data — the
 * warning count shown here (when present) is purely informational,
 * surfaced before the toggle, not a hard stop.
 */
export default function SettingsModules() {
    const canManage = useCan('tenant.modules.manage');

    const [modules, setModules] = useState<TenantModuleState[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [togglingKey, setTogglingKey] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<{ data: TenantModuleState[] }>('/tenant/modules')
            .then((response) => setModules(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const toggle = (module: TenantModuleState) => {
        setTogglingKey(module.module_key);
        setError(null);

        api.patch<{ data: TenantModuleState }>(`/tenant/modules/${module.module_key}`, { enabled: !module.enabled })
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setTogglingKey(null));
    };

    return (
        <AppLayout>
            <Head title="Modules" />

            <PageHeader
                title="Modules"
                description="Enable or disable optional modules for your organisation. Disabling a module hides it and blocks its API — existing data is never deleted."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />

            {error && <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>}

            {modules === null && !error && <LoadingState label="Loading modules…" />}

            {modules !== null && (
                <div className="space-y-3">
                    {modules.map((module) => (
                        <Card key={module.module_key}>
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <p className="font-medium text-slate-900">{module.label}</p>
                                        <Badge tone={module.enabled ? 'success' : 'neutral'}>
                                            {module.enabled ? 'Enabled' : 'Disabled'}
                                        </Badge>
                                    </div>
                                    <p className="mt-1 text-sm text-slate-500">{module.description}</p>
                                    {module.enabled && module.warning_count !== null && module.warning_count > 0 && (
                                        <p className="mt-1 text-xs text-amber-600">
                                            {module.warning_count} active item{module.warning_count === 1 ? '' : 's'} in this module right
                                            now.
                                        </p>
                                    )}
                                </div>
                                {canManage && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => toggle(module)}
                                        disabled={togglingKey === module.module_key}
                                    >
                                        {togglingKey === module.module_key ? 'Saving…' : module.enabled ? 'Disable' : 'Enable'}
                                    </Button>
                                )}
                            </div>
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
