import { Head, Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { PaginatedResponse, Policy, PolicyVersion } from '@/types/policy';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    policyId: string;
}

const statusTone: Record<Policy['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    published: 'success',
    archived: 'neutral',
};

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

/**
 * Content is rendered as plain text only (Refinement 9) — never
 * `dangerouslySetInnerHTML`. React already escapes text children, so
 * `{content}` here cannot execute markup even if a version's content
 * happened to contain HTML-looking text.
 */
export default function PolicyShow() {
    const { policyId } = usePage<ShowProps>().props;
    const canUpdate = useCan('policies.update');
    const canPublish = useCan('policies.publish');
    const canAssign = useCan('policies.assign');
    const canAcknowledge = useCan('policies.acknowledge');

    const [policy, setPolicy] = useState<Policy | null>(null);
    const [policyError, setPolicyError] = useState<ApiError | null>(null);

    const [versions, setVersions] = useState<PolicyVersion[] | null>(null);

    const [selectedDraftId, setSelectedDraftId] = useState('');
    const [publishing, setPublishing] = useState(false);
    const [publishError, setPublishError] = useState<string | null>(null);

    const [acknowledging, setAcknowledging] = useState(false);
    const [acknowledgeMessage, setAcknowledgeMessage] = useState<string | null>(null);
    const [acknowledgeError, setAcknowledgeError] = useState<string | null>(null);

    const loadPolicy = useCallback(() => {
        api.get<{ data: Policy }>(`/policies/${policyId}`)
            .then((response) => setPolicy(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setPolicyError(apiError);
                }
            });
    }, [policyId]);

    const loadVersions = useCallback(() => {
        api.get<PaginatedResponse<PolicyVersion>>(`/policies/${policyId}/versions`)
            .then((response) => setVersions(response.data.data))
            .catch(() => setVersions([]));
    }, [policyId]);

    useEffect(() => {
        loadPolicy();
        loadVersions();
    }, [loadPolicy, loadVersions]);

    const currentVersion = useMemo(
        () => versions?.find((version) => version.id === policy?.current_version_id) ?? null,
        [versions, policy],
    );

    const draftVersions = useMemo(() => versions?.filter((version) => version.status === 'draft') ?? [], [versions]);

    useEffect(() => {
        if (draftVersions.length === 1) {
            setSelectedDraftId(draftVersions[0].id);
        }
    }, [draftVersions]);

    const handlePublish = () => {
        if (!selectedDraftId) return;
        const version = draftVersions.find((v) => v.id === selectedDraftId);
        if (!version) return;

        if (!window.confirm(`Publish version ${version.version_number} ("${version.title}")? This archives the currently published version, if any.`)) {
            return;
        }

        setPublishing(true);
        setPublishError(null);

        api.post(`/policies/${policyId}/publish`, { policy_version_id: selectedDraftId })
            .then(() => {
                loadPolicy();
                loadVersions();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setPublishError(apiError.errors?.policy_version_id?.[0] ?? apiError.message);
                }
            })
            .finally(() => setPublishing(false));
    };

    /**
     * Refinement 5 — no employee_id is ever sent; the backend resolves
     * the caller's own linked employee. This button never attempts to
     * acknowledge on behalf of anyone else.
     */
    const handleAcknowledge = () => {
        setAcknowledging(true);
        setAcknowledgeError(null);
        setAcknowledgeMessage(null);

        api.post(`/policies/${policyId}/acknowledge`)
            .then(() => setAcknowledgeMessage('Policy acknowledged.'))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setAcknowledgeError(apiError.message);
                }
            })
            .finally(() => setAcknowledging(false));
    };

    if (policyError) {
        return (
            <AppLayout>
                <Head title="Policy" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{policyError.message}</div>
            </AppLayout>
        );
    }

    if (!policy) {
        return (
            <AppLayout>
                <Head title="Policy" />
                <LoadingState label="Loading policy…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={policy.title} />

            <PageHeader
                title={policy.title}
                description={
                    <>
                        {policy.code ?? 'No code'} · <Badge tone={statusTone[policy.status]}>{policy.status}</Badge>
                    </>
                }
                actions={
                    <div className="flex flex-wrap items-center gap-3">
                        <PermissionGate permission="policies.view_acknowledgements">
                            <Link
                                href={`/policies/${policyId}/acknowledgements`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Acknowledgements
                            </Link>
                        </PermissionGate>
                        <PermissionGate permission="policies.update">
                            <Link
                                href={`/policies/${policyId}/edit`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Edit
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Description" value={policy.description} />
                        <Field label="Category" value={policy.category} />
                        <Field label="Effective date" value={policy.effective_date} />
                        <Field label="Review date" value={policy.review_date} />
                    </dl>
                </Card>

                <Card title="Current version">
                    {currentVersion ? (
                        <dl className="divide-y divide-slate-100">
                            <Field label="Version" value={String(currentVersion.version_number)} />
                            <Field label="Summary" value={currentVersion.summary} />
                            {currentVersion.content && (
                                <div className="py-2 text-sm">
                                    <dt className="mb-1 text-slate-500">Content</dt>
                                    <dd className="whitespace-pre-wrap rounded-md bg-slate-50 p-3 font-mono text-xs text-slate-900">
                                        {currentVersion.content}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    ) : (
                        <p className="text-sm text-slate-500">This policy has no published version yet.</p>
                    )}
                </Card>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <PermissionGate permission="policies.update">
                    <Card title="Versions">
                        <div className="flex items-center justify-between">
                            <p className="text-sm text-slate-500">
                                {versions === null ? 'Loading…' : `${versions.length} version${versions.length === 1 ? '' : 's'} total.`}
                            </p>
                            <Link
                                href={`/policies/${policyId}/versions/create`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Create version
                            </Link>
                        </div>

                        {canPublish && (
                            <div className="mt-4 border-t border-slate-100 pt-4">
                                {publishError && (
                                    <div className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{publishError}</div>
                                )}

                                {draftVersions.length === 0 ? (
                                    <p className="text-sm text-slate-500">No draft versions available to publish.</p>
                                ) : (
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                        <select
                                            value={selectedDraftId}
                                            onChange={(e) => setSelectedDraftId(e.target.value)}
                                            className="block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                                        >
                                            <option value="">Select a draft version…</option>
                                            {draftVersions.map((version) => (
                                                <option key={version.id} value={version.id}>
                                                    v{version.version_number} — {version.title}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="button"
                                            disabled={!selectedDraftId || publishing}
                                            onClick={handlePublish}
                                        >
                                            {publishing ? 'Publishing…' : 'Publish'}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </Card>
                </PermissionGate>

                <Card title="Actions">
                    <div className="flex flex-col gap-3">
                        <PermissionGate permission="policies.assign">
                            {policy.current_version_id ? (
                                <Link
                                    href={`/policies/${policyId}/assign`}
                                    className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    Assign to employees
                                </Link>
                            ) : (
                                <p className="text-sm text-slate-500">Publish a version first to enable assignment.</p>
                            )}
                        </PermissionGate>

                        {canAcknowledge && (
                            <div>
                                {acknowledgeMessage && (
                                    <p className="mb-2 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{acknowledgeMessage}</p>
                                )}
                                {acknowledgeError && (
                                    <p className="mb-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{acknowledgeError}</p>
                                )}
                                <Button type="button" variant="secondary" disabled={acknowledging} onClick={handleAcknowledge}>
                                    {acknowledging ? 'Working…' : 'Acknowledge'}
                                </Button>
                            </div>
                        )}

                        {!canUpdate && !canAssign && !canAcknowledge && (
                            <p className="text-sm text-slate-500">No actions available for your account on this policy.</p>
                        )}
                    </div>
                </Card>
            </div>
        </AppLayout>
    );
}
