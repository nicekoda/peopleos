import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import {
    HR_DOCUMENT_TYPE_LABELS,
    HrDocumentTemplate,
    HrDocumentTemplateFormPayload,
    HrDocumentTemplateVersion,
    HrDocumentTemplateVersionStatus,
    PaginatedResponse,
} from '@/types/hrDocument';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    hrDocumentTemplateId: string;
}

const versionStatusTone: Record<HrDocumentTemplateVersionStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    published: 'success',
    archived: 'neutral',
};

/**
 * Checkpoint 36 — metadata only (title/description/document_type/status);
 * `content_template` moved to HrDocumentTemplateVersion and is managed
 * below via the Versions card, not this form. `status` remains the one
 * field this form adds beyond Create — deactivating a template hides it
 * from new generation without archiving it entirely.
 */
export default function SettingsHrDocumentTemplateEdit() {
    const { hrDocumentTemplateId } = usePage<EditProps>().props;

    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [form, setForm] = useState<HrDocumentTemplateFormPayload | null>(null);
    const [currentVersionId, setCurrentVersionId] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const [versions, setVersions] = useState<HrDocumentTemplateVersion[] | null>(null);
    const [publishingId, setPublishingId] = useState<string | null>(null);
    const [versionsError, setVersionsError] = useState<string | null>(null);

    const loadTemplate = useCallback(() => {
        api.get<{ data: HrDocumentTemplate }>(`/hr-document-templates/${hrDocumentTemplateId}`)
            .then((response) => {
                const template = response.data.data;
                setForm({
                    title: template.title,
                    description: template.description ?? '',
                    document_type: template.document_type,
                    status: template.status,
                });
                setCurrentVersionId(template.current_version_id);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [hrDocumentTemplateId]);

    const loadVersions = useCallback(() => {
        api.get<PaginatedResponse<HrDocumentTemplateVersion>>(`/hr-document-templates/${hrDocumentTemplateId}/versions`)
            .then((response) => setVersions(response.data.data))
            .catch(() => setVersions([]));
    }, [hrDocumentTemplateId]);

    useEffect(() => {
        loadTemplate();
        loadVersions();
    }, [loadTemplate, loadVersions]);

    const set = <K extends keyof HrDocumentTemplateFormPayload>(key: K, value: HrDocumentTemplateFormPayload[K]) => {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!form) return;

        setSubmitting(true);
        setErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        api.patch<{ data: HrDocumentTemplate }>(`/hr-document-templates/${hrDocumentTemplateId}`, form)
            .then(() => setSuccessMessage('Template updated.'))
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (apiError.errors) {
                    setErrors(apiError.errors);
                }
                setGeneralError(apiError.message);
            })
            .finally(() => setSubmitting(false));
    };

    const handlePublish = (version: HrDocumentTemplateVersion) => {
        if (!window.confirm(`Publish version ${version.version_number}? This archives the currently published version, if any.`)) {
            return;
        }

        setPublishingId(version.id);
        setVersionsError(null);

        api.post(`/hr-document-template-versions/${version.id}/publish`)
            .then(() => {
                loadTemplate();
                loadVersions();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setVersionsError(apiError.message);
                }
            })
            .finally(() => setPublishingId(null));
    };

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Edit HR document template" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!form) {
        return (
            <AppLayout>
                <Head title="Edit HR document template" />
                <LoadingState label="Loading template…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Edit HR document template" />

            <PageHeader title="Edit HR document template" />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <InputField
                            label="Title"
                            name="title"
                            required
                            value={form.title}
                            onChange={(e) => set('title', e.target.value)}
                            error={fieldError('title')}
                        />
                        <SelectField
                            label="Document type"
                            name="document_type"
                            required
                            value={form.document_type}
                            onChange={(e) => set('document_type', e.target.value as HrDocumentTemplateFormPayload['document_type'])}
                            error={fieldError('document_type')}
                        >
                            <option value="">— Select a type —</option>
                            {Object.entries(HR_DOCUMENT_TYPE_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Status"
                            name="status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value as HrDocumentTemplateFormPayload['status'])}
                            error={fieldError('status')}
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </SelectField>

                        <div className="sm:col-span-2">
                            <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                Description
                            </label>
                            <textarea
                                id="description"
                                rows={2}
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('description')} />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => router.visit('/settings/hr-document-templates')}
                        >
                            Back to list
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </Card>
            </form>

            <div className="mt-4">
                <Card title="Versions">
                    <div className="mb-4 flex items-center justify-between">
                        <p className="text-sm text-slate-500">
                            {versions === null ? 'Loading…' : `${versions.length} version${versions.length === 1 ? '' : 's'} total.`}
                        </p>
                        <Link
                            href={`/settings/hr-document-templates/${hrDocumentTemplateId}/versions/create`}
                            className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            Create new version
                        </Link>
                    </div>

                    {versionsError && (
                        <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{versionsError}</div>
                    )}

                    {versions !== null && versions.length > 0 && (
                        <div className="overflow-x-auto rounded-lg border border-slate-200">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-semibold text-slate-600">Version</th>
                                        <th className="px-4 py-2 text-left font-semibold text-slate-600">Status</th>
                                        <th className="px-4 py-2 text-left font-semibold text-slate-600">Created</th>
                                        <th className="px-4 py-2 text-right font-semibold text-slate-600">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {versions.map((version) => (
                                        <tr key={version.id}>
                                            <td className="whitespace-nowrap px-4 py-2 font-medium text-slate-900">
                                                v{version.version_number}
                                                {version.id === currentVersionId && (
                                                    <span className="ml-2 text-xs text-indigo-600">(current)</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-2">
                                                <Badge tone={versionStatusTone[version.status]}>{version.status}</Badge>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-2 text-slate-500">{version.created_at?.slice(0, 10)}</td>
                                            <td className="whitespace-nowrap px-4 py-2 text-right">
                                                <div className="flex justify-end gap-3">
                                                    {version.status === 'draft' && (
                                                        <Link
                                                            href={`/settings/hr-document-template-versions/${version.id}/edit`}
                                                            className="text-indigo-600 hover:text-indigo-500"
                                                        >
                                                            Edit
                                                        </Link>
                                                    )}
                                                    {version.id !== currentVersionId && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handlePublish(version)}
                                                            disabled={publishingId === version.id}
                                                            className="text-indigo-600 hover:text-indigo-500 disabled:opacity-50"
                                                        >
                                                            {publishingId === version.id ? 'Publishing…' : 'Publish'}
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
