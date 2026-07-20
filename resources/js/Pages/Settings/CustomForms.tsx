import { Head, Link } from '@inertiajs/react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import CustomFieldInput from '@/Components/CustomFieldInput';
import { InputField } from '@/Components/FormField';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { CustomFieldDefinitionState } from '@/types/customField';
import { CustomFormState, CustomFormSectionState, CustomFormFieldState } from '@/types/customForm';

// Checkpoint 52 — Employee is the only supported entity for this
// checkpoint's forms. Additional tabs are added here the same way
// Settings/CustomFields.tsx grew its own tab list across Checkpoints
// 48/49/51 — simple tabs, not a dropdown, while the list stays short.
const ENTITY_TABS: { value: string; label: string }[] = [{ value: 'employee', label: 'Employees' }];

/**
 * Checkpoint 52 — basic Settings UI: list/create/edit-name-description/
 * add-section/edit-section/add-existing-field/reorder/enable-disable/
 * preview only. No drag-and-drop designer — reorder uses the same
 * simple up/down sort_order-swap pattern Settings/CustomFields.tsx
 * already established. form_key/section_key are never editable once
 * created (immutable) — the create forms are the only place they're
 * ever typed. A form field can only ever reference an existing custom
 * field (the picker is filtered to this entity's own active fields) —
 * arbitrary inline field definitions are not supported here, matching
 * the approved MVP scope.
 */
export default function SettingsCustomForms() {
    const canManage = useCan('custom_forms.manage');

    const [entityType, setEntityType] = useState(ENTITY_TABS[0].value);
    const [forms, setForms] = useState<CustomFormState[] | null>(null);
    const [availableFields, setAvailableFields] = useState<CustomFieldDefinitionState[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [saving, setSaving] = useState(false);

    const [showCreateForm, setShowCreateForm] = useState(false);
    const [createFormName, setCreateFormName] = useState('');
    const [createFormKey, setCreateFormKey] = useState('');
    const [createFormErrors, setCreateFormErrors] = useState<Record<string, string[]>>({});

    const [editingFormId, setEditingFormId] = useState<string | null>(null);
    const [editFormName, setEditFormName] = useState('');
    const [editFormDescription, setEditFormDescription] = useState('');
    const [editFormErrors, setEditFormErrors] = useState<Record<string, string[]>>({});

    const [addingSectionForFormId, setAddingSectionForFormId] = useState<string | null>(null);
    const [newSectionKey, setNewSectionKey] = useState('');
    const [newSectionTitle, setNewSectionTitle] = useState('');
    const [sectionErrors, setSectionErrors] = useState<Record<string, string[]>>({});

    const [addingFieldForSectionId, setAddingFieldForSectionId] = useState<string | null>(null);
    const [newFieldDefinitionId, setNewFieldDefinitionId] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    const [previewFormId, setPreviewFormId] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        Promise.all([
            api.get<{ data: CustomFormState[] }>(`/custom-forms/${entityType}`),
            api.get<{ data: CustomFieldDefinitionState[] }>(`/custom-fields/${entityType}`),
        ])
            .then(([formsResponse, fieldsResponse]) => {
                setForms(formsResponse.data.data);
                setAvailableFields(fieldsResponse.data.data);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [entityType]);

    useEffect(() => {
        setShowCreateForm(false);
        setCreateFormName('');
        setCreateFormKey('');
        setCreateFormErrors({});
        setEditingFormId(null);
        setAddingSectionForFormId(null);
        setAddingFieldForSectionId(null);
        setPreviewFormId(null);
        setForms(null);
        setAvailableFields(null);
        load();
    }, [load]);

    const submitCreateForm: FormEventHandler = (e) => {
        e.preventDefault();
        setSaving(true);
        setCreateFormErrors({});

        api.post(`/custom-forms/${entityType}`, { form_key: createFormKey, name: createFormName })
            .then(() => {
                setShowCreateForm(false);
                setCreateFormName('');
                setCreateFormKey('');
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setCreateFormErrors(apiError.errors ?? { form_key: [apiError.message] });
            })
            .finally(() => setSaving(false));
    };

    const startEditForm = (form: CustomFormState) => {
        setEditingFormId(form.id);
        setEditFormErrors({});
        setEditFormName(form.name);
        setEditFormDescription(form.description ?? '');
    };

    const submitEditForm = (form: CustomFormState) => {
        setSaving(true);
        setEditFormErrors({});

        api.patch(`/custom-forms/${form.id}`, { name: editFormName, description: editFormDescription || null })
            .then(() => {
                setEditingFormId(null);
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setEditFormErrors(apiError.errors ?? { name: [apiError.message] });
            })
            .finally(() => setSaving(false));
    };

    const toggleFormStatus = (form: CustomFormState) => {
        setSaving(true);
        api.patch(`/custom-forms/${form.id}`, { status: form.status === 'active' ? 'inactive' : 'active' })
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const moveForm = (form: CustomFormState, direction: -1 | 1) => {
        if (!forms) return;
        const sorted = [...forms].sort((a, b) => a.sort_order - b.sort_order);
        const index = sorted.findIndex((f) => f.id === form.id);
        const swapWith = sorted[index + direction];
        if (!swapWith) return;

        setSaving(true);
        Promise.all([
            api.patch(`/custom-forms/${form.id}`, { sort_order: swapWith.sort_order }),
            api.patch(`/custom-forms/${swapWith.id}`, { sort_order: form.sort_order }),
        ])
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const submitCreateSection: FormEventHandler = (e) => {
        e.preventDefault();
        if (!addingSectionForFormId) return;
        setSaving(true);
        setSectionErrors({});

        api.post(`/custom-forms/${addingSectionForFormId}/sections`, { section_key: newSectionKey, title: newSectionTitle })
            .then(() => {
                setAddingSectionForFormId(null);
                setNewSectionKey('');
                setNewSectionTitle('');
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setSectionErrors(apiError.errors ?? { section_key: [apiError.message] });
            })
            .finally(() => setSaving(false));
    };

    const toggleSectionStatus = (section: CustomFormSectionState) => {
        setSaving(true);
        api.patch(`/custom-form-sections/${section.id}`, { status: section.status === 'active' ? 'inactive' : 'active' })
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const moveSection = (form: CustomFormState, section: CustomFormSectionState, direction: -1 | 1) => {
        const sorted = [...form.sections].sort((a, b) => a.sort_order - b.sort_order);
        const index = sorted.findIndex((s) => s.id === section.id);
        const swapWith = sorted[index + direction];
        if (!swapWith) return;

        setSaving(true);
        Promise.all([
            api.patch(`/custom-form-sections/${section.id}`, { sort_order: swapWith.sort_order }),
            api.patch(`/custom-form-sections/${swapWith.id}`, { sort_order: section.sort_order }),
        ])
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const submitAddField: FormEventHandler = (e) => {
        e.preventDefault();
        if (!addingFieldForSectionId || !newFieldDefinitionId) return;
        setSaving(true);
        setFieldErrors({});

        api.post(`/custom-form-sections/${addingFieldForSectionId}/fields`, { custom_field_definition_id: newFieldDefinitionId })
            .then(() => {
                setAddingFieldForSectionId(null);
                setNewFieldDefinitionId('');
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setFieldErrors(apiError.errors ?? { custom_field_definition_id: [apiError.message] });
            })
            .finally(() => setSaving(false));
    };

    const toggleFieldStatus = (field: CustomFormFieldState) => {
        setSaving(true);
        api.patch(`/custom-form-fields/${field.id}`, { status: field.status === 'active' ? 'inactive' : 'active' })
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const moveField = (section: CustomFormSectionState, field: CustomFormFieldState, direction: -1 | 1) => {
        const sorted = [...section.fields].sort((a, b) => a.sort_order - b.sort_order);
        const index = sorted.findIndex((f) => f.id === field.id);
        const swapWith = sorted[index + direction];
        if (!swapWith) return;

        setSaving(true);
        Promise.all([
            api.patch(`/custom-form-fields/${field.id}`, { sort_order: swapWith.sort_order }),
            api.patch(`/custom-form-fields/${swapWith.id}`, { sort_order: field.sort_order }),
        ])
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const sortedForms = forms ? [...forms].sort((a, b) => a.sort_order - b.sort_order) : null;

    // Fields not yet used in ANY section of the form being edited — the
    // add-field picker only ever offers these, but the backend
    // independently re-verifies tenant/entity match and non-duplication
    // regardless of what this filter shows (see CustomFormDefinitionService).
    const fieldsAlreadyInForm = (form: CustomFormState): Set<string> =>
        new Set(form.sections.flatMap((s) => s.fields.map((f) => f.custom_field_definition.id)));

    return (
        <AppLayout>
            <Head title="Custom Forms" />

            <PageHeader
                title="Custom Forms"
                description="Group existing custom fields into sections for display on an entity's own page. Disabling a form/section/field preserves its configuration — it just stops appearing on the live entity page."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />

            <div className="mb-6 border-b border-slate-200">
                <nav className="-mb-px flex gap-6">
                    {ENTITY_TABS.map((tab) => (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => setEntityType(tab.value)}
                            className={`border-b-2 px-1 py-3 text-sm font-medium ${
                                entityType === tab.value
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>
            </div>

            {error && <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>}

            {sortedForms === null && !error && <LoadingState label="Loading custom forms…" />}

            {sortedForms !== null && (
                <div className="space-y-4">
                    {canManage && !showCreateForm && (
                        <Button type="button" onClick={() => setShowCreateForm(true)}>
                            Add form
                        </Button>
                    )}

                    {showCreateForm && (
                        <Card title="New custom form">
                            <form onSubmit={submitCreateForm} className="space-y-4">
                                <InputField
                                    label="Form key"
                                    name="form_key"
                                    value={createFormKey}
                                    onChange={(e) => setCreateFormKey(e.target.value)}
                                    placeholder="employee_additional_info"
                                    error={createFormErrors.form_key?.[0]}
                                    required
                                />
                                <p className="-mt-3 text-xs text-slate-500">Lowercase snake_case, cannot be changed later.</p>
                                <InputField
                                    label="Name"
                                    name="name"
                                    value={createFormName}
                                    onChange={(e) => setCreateFormName(e.target.value)}
                                    error={createFormErrors.name?.[0]}
                                    required
                                />
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="secondary" onClick={() => setShowCreateForm(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={saving}>
                                        {saving ? 'Saving…' : 'Create form'}
                                    </Button>
                                </div>
                            </form>
                        </Card>
                    )}

                    {sortedForms.length === 0 && !showCreateForm && <p className="text-sm text-slate-500">No custom forms defined yet.</p>}

                    {sortedForms.map((form, formIndex) => {
                        const usedFieldIds = fieldsAlreadyInForm(form);
                        const pickableFields = (availableFields ?? []).filter(
                            (f) => f.status === 'active' && !usedFieldIds.has(f.id),
                        );
                        const sortedSections = [...form.sections].sort((a, b) => a.sort_order - b.sort_order);

                        return (
                            <Card key={form.id}>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="min-w-0 flex-1">
                                        {editingFormId === form.id ? (
                                            <div className="space-y-3">
                                                <InputField
                                                    label="Name"
                                                    name={`edit-name-${form.id}`}
                                                    value={editFormName}
                                                    onChange={(e) => setEditFormName(e.target.value)}
                                                    error={editFormErrors.name?.[0]}
                                                />
                                                <InputField
                                                    label="Description"
                                                    name={`edit-description-${form.id}`}
                                                    value={editFormDescription}
                                                    onChange={(e) => setEditFormDescription(e.target.value)}
                                                />
                                                <div className="flex justify-end gap-2">
                                                    <Button type="button" variant="secondary" onClick={() => setEditingFormId(null)}>
                                                        Cancel
                                                    </Button>
                                                    <Button type="button" onClick={() => submitEditForm(form)} disabled={saving}>
                                                        {saving ? 'Saving…' : 'Save'}
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : (
                                            <>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-medium text-slate-900">{form.name}</p>
                                                    <span className="text-xs text-slate-400">({form.form_key})</span>
                                                    <Badge tone={form.status === 'active' ? 'success' : 'neutral'}>
                                                        {form.status === 'active' ? 'Active' : 'Disabled'}
                                                    </Badge>
                                                </div>
                                                {form.description && <p className="mt-1 text-sm text-slate-500">{form.description}</p>}
                                            </>
                                        )}
                                    </div>
                                    {canManage && editingFormId !== form.id && (
                                        <div className="flex shrink-0 flex-col items-end gap-2">
                                            <div className="flex gap-1">
                                                <Button type="button" variant="secondary" onClick={() => moveForm(form, -1)} disabled={formIndex === 0 || saving}>
                                                    ↑
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => moveForm(form, 1)} disabled={formIndex === sortedForms.length - 1 || saving}>
                                                    ↓
                                                </Button>
                                            </div>
                                            <div className="flex gap-2">
                                                <Button type="button" variant="secondary" onClick={() => setPreviewFormId(previewFormId === form.id ? null : form.id)}>
                                                    {previewFormId === form.id ? 'Hide preview' : 'Preview'}
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => startEditForm(form)}>
                                                    Edit
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => toggleFormStatus(form)} disabled={saving}>
                                                    {form.status === 'active' ? 'Disable' : 'Enable'}
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </div>

                                <div className="mt-4 space-y-3 border-t border-slate-100 pt-4">
                                    {sortedSections.map((section, sectionIndex) => {
                                        const sortedFields = [...section.fields].sort((a, b) => a.sort_order - b.sort_order);

                                        return (
                                            <div key={section.id} className="rounded-md border border-slate-200 p-3">
                                                <div className="flex items-start justify-between gap-4">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="text-sm font-semibold text-slate-800">{section.title}</p>
                                                        <span className="text-xs text-slate-400">({section.section_key})</span>
                                                        <Badge tone={section.status === 'active' ? 'success' : 'neutral'}>
                                                            {section.status === 'active' ? 'Active' : 'Disabled'}
                                                        </Badge>
                                                    </div>
                                                    {canManage && (
                                                        <div className="flex shrink-0 gap-1">
                                                            <Button type="button" variant="secondary" onClick={() => moveSection(form, section, -1)} disabled={sectionIndex === 0 || saving}>
                                                                ↑
                                                            </Button>
                                                            <Button type="button" variant="secondary" onClick={() => moveSection(form, section, 1)} disabled={sectionIndex === sortedSections.length - 1 || saving}>
                                                                ↓
                                                            </Button>
                                                            <Button type="button" variant="secondary" onClick={() => toggleSectionStatus(section)} disabled={saving}>
                                                                {section.status === 'active' ? 'Disable' : 'Enable'}
                                                            </Button>
                                                        </div>
                                                    )}
                                                </div>

                                                <ul className="mt-2 space-y-1">
                                                    {sortedFields.map((field, fieldIndex) => (
                                                        <li key={field.id} className="flex items-center justify-between gap-4 text-sm">
                                                            <span>
                                                                {field.label_override ?? field.custom_field_definition.label}{' '}
                                                                <span className="text-xs text-slate-400">({field.custom_field_definition.field_key})</span>
                                                                {field.custom_field_definition.sensitivity !== 'normal' && (
                                                                    <Badge tone="warning">{field.custom_field_definition.sensitivity}</Badge>
                                                                )}
                                                                {field.status === 'inactive' && <Badge tone="neutral">Disabled</Badge>}
                                                            </span>
                                                            {canManage && (
                                                                <div className="flex shrink-0 gap-1">
                                                                    <Button type="button" variant="secondary" onClick={() => moveField(section, field, -1)} disabled={fieldIndex === 0 || saving}>
                                                                        ↑
                                                                    </Button>
                                                                    <Button type="button" variant="secondary" onClick={() => moveField(section, field, 1)} disabled={fieldIndex === sortedFields.length - 1 || saving}>
                                                                        ↓
                                                                    </Button>
                                                                    <Button type="button" variant="secondary" onClick={() => toggleFieldStatus(field)} disabled={saving}>
                                                                        {field.status === 'active' ? 'Remove' : 'Restore'}
                                                                    </Button>
                                                                </div>
                                                            )}
                                                        </li>
                                                    ))}
                                                    {sortedFields.length === 0 && <li className="text-xs text-slate-400">No fields in this section yet.</li>}
                                                </ul>

                                                {canManage && (
                                                    addingFieldForSectionId === section.id ? (
                                                        <form onSubmit={submitAddField} className="mt-3 flex items-end gap-2">
                                                            <div className="flex-1">
                                                                <label className="block text-xs font-medium text-slate-700">Existing custom field</label>
                                                                <select
                                                                    className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300"
                                                                    value={newFieldDefinitionId}
                                                                    onChange={(e) => setNewFieldDefinitionId(e.target.value)}
                                                                >
                                                                    <option value="">— Select a field —</option>
                                                                    {pickableFields.map((f) => (
                                                                        <option key={f.id} value={f.id}>
                                                                            {f.label} ({f.field_key})
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                                {fieldErrors.custom_field_definition_id && (
                                                                    <p className="mt-1 text-xs text-red-600">{fieldErrors.custom_field_definition_id[0]}</p>
                                                                )}
                                                            </div>
                                                            <Button type="button" variant="secondary" onClick={() => setAddingFieldForSectionId(null)}>
                                                                Cancel
                                                            </Button>
                                                            <Button type="submit" disabled={saving || !newFieldDefinitionId}>
                                                                Add
                                                            </Button>
                                                        </form>
                                                    ) : (
                                                        <Button type="button" variant="secondary" className="mt-3" onClick={() => setAddingFieldForSectionId(section.id)}>
                                                            Add existing field
                                                        </Button>
                                                    )
                                                )}
                                            </div>
                                        );
                                    })}

                                    {canManage && (
                                        addingSectionForFormId === form.id ? (
                                            <form onSubmit={submitCreateSection} className="rounded-md border border-dashed border-slate-300 p-3">
                                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                                    <InputField
                                                        label="Section key"
                                                        name={`section-key-${form.id}`}
                                                        value={newSectionKey}
                                                        onChange={(e) => setNewSectionKey(e.target.value)}
                                                        placeholder="general"
                                                        error={sectionErrors.section_key?.[0]}
                                                        required
                                                    />
                                                    <InputField
                                                        label="Title"
                                                        name={`section-title-${form.id}`}
                                                        value={newSectionTitle}
                                                        onChange={(e) => setNewSectionTitle(e.target.value)}
                                                        error={sectionErrors.title?.[0]}
                                                        required
                                                    />
                                                </div>
                                                <div className="mt-3 flex justify-end gap-2">
                                                    <Button type="button" variant="secondary" onClick={() => setAddingSectionForFormId(null)}>
                                                        Cancel
                                                    </Button>
                                                    <Button type="submit" disabled={saving}>
                                                        Add section
                                                    </Button>
                                                </div>
                                            </form>
                                        ) : (
                                            <Button type="button" variant="secondary" onClick={() => setAddingSectionForFormId(form.id)}>
                                                Add section
                                            </Button>
                                        )
                                    )}
                                </div>

                                {previewFormId === form.id && (
                                    <div className="mt-4 border-t border-slate-100 pt-4">
                                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Preview (read-only)</p>
                                        <div className="space-y-4">
                                            {sortedSections.filter((s) => s.status === 'active').map((section) => (
                                                <div key={section.id}>
                                                    <p className="text-sm font-semibold text-slate-800">{section.title}</p>
                                                    <div className="mt-2 space-y-3">
                                                        {[...section.fields].sort((a, b) => a.sort_order - b.sort_order).map((field) => (
                                                            <div key={field.id}>
                                                                <label className="block text-sm font-medium text-slate-700">
                                                                    {field.label_override ?? field.custom_field_definition.label}{' '}
                                                                    {(field.is_required_override ?? field.custom_field_definition.is_required) && (
                                                                        <span className="text-red-500">*</span>
                                                                    )}
                                                                </label>
                                                                <CustomFieldInput field={field.custom_field_definition} value="" onChange={() => {}} canEdit={false} />
                                                                {field.help_text && <p className="mt-1 text-xs text-slate-400">{field.help_text}</p>}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </Card>
                        );
                    })}
                </div>
            )}
        </AppLayout>
    );
}
