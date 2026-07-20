import { Head, Link } from '@inertiajs/react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import {
    CustomFieldDefinitionState,
    CustomFieldOptionState,
    CustomFieldVisibilityRuleState,
    CUSTOM_FIELD_SENSITIVITIES,
    CUSTOM_FIELD_TYPES,
} from '@/types/customField';
import { Role } from '@/types/role';
import { PaginatedResponse } from '@/types/employee';

// Checkpoint 49 added Job Application as entity #2 — simple tabs, not a
// dropdown, since only two entities are supported (decision 5). See
// docs/architecture.md for the roadmap (lifecycle_processes/leave_requests
// next, employees last).
const ENTITY_TABS: { value: string; label: string }[] = [
    { value: 'recruitment_applicant', label: 'Recruitment Applicants' },
    { value: 'job_application', label: 'Job Applications' },
    { value: 'employee', label: 'Employees' },
];

interface FieldFormState {
    field_key: string;
    label: string;
    description: string;
    field_type: string;
    is_required: boolean;
    default_value: string;
    sensitivity: string;
    options: { option_key: string; label: string; status: string }[];
}

const emptyForm: FieldFormState = {
    field_key: '',
    label: '',
    description: '',
    field_type: 'text',
    is_required: false,
    default_value: '',
    sensitivity: 'normal',
    options: [],
};

function PreviewInput({ fieldType, defaultValue, options }: { fieldType: string; defaultValue: string; options: CustomFieldOptionState[] }) {
    if (fieldType === 'textarea') {
        return <textarea disabled className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300" defaultValue={defaultValue} />;
    }
    if (fieldType === 'boolean') {
        return <input type="checkbox" disabled defaultChecked={defaultValue === 'true' || defaultValue === '1'} className="mt-1 h-4 w-4" />;
    }
    if (fieldType === 'single_select' || fieldType === 'multi_select') {
        return (
            <select disabled multiple={fieldType === 'multi_select'} className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                {options.map((option) => (
                    <option key={option.option_key} value={option.option_key}>
                        {option.label}
                        {option.status === 'inactive' ? ' (disabled)' : ''}
                    </option>
                ))}
            </select>
        );
    }

    const inputType = fieldType === 'number' ? 'number' : fieldType === 'date' ? 'date' : fieldType === 'email' ? 'email' : fieldType === 'url' ? 'url' : 'text';

    return <input disabled type={inputType} defaultValue={defaultValue} className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300" />;
}

/**
 * Checkpoint 48 — basic Settings UI: list/create/edit/enable-disable/
 * reorder/preview only. No drag-and-drop designer, no custom form
 * builder — reorder uses simple up/down sort_order swaps. field_key and
 * option_key are never editable once created (immutable, see
 * docs/architecture.md); the create form is the only place they're
 * ever typed.
 */
export default function SettingsCustomFields() {
    const canManage = useCan('custom_fields.manage');

    const [entityType, setEntityType] = useState(ENTITY_TABS[0].value);
    const [fields, setFields] = useState<CustomFieldDefinitionState[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [showCreate, setShowCreate] = useState(false);
    const [createForm, setCreateForm] = useState<FieldFormState>(emptyForm);
    const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({});
    const [saving, setSaving] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);
    const [editForm, setEditForm] = useState<FieldFormState>(emptyForm);
    const [editErrors, setEditErrors] = useState<Record<string, string[]>>({});

    // Checkpoint 53 — roles aren't entity-scoped, so this loads once
    // (only ever needed by whoever can reach this page's manage state,
    // i.e. Tenant Admin, who always holds roles.view too).
    const [roles, setRoles] = useState<Role[] | null>(null);
    const [ruleForm, setRuleForm] = useState({ role_id: '', can_view: true, can_edit: false });
    const [ruleErrors, setRuleErrors] = useState<Record<string, string[]>>({});
    const [ruleSaving, setRuleSaving] = useState(false);

    const load = useCallback(() => {
        setError(null);
        api.get<{ data: CustomFieldDefinitionState[] }>(`/custom-fields/${entityType}`)
            .then((response) => setFields(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [entityType]);

    useEffect(() => {
        // Switching tabs discards any in-progress create/edit state —
        // it belongs to the previous entity's fields, not this one.
        setShowCreate(false);
        setCreateForm(emptyForm);
        setCreateErrors({});
        setEditingId(null);
        setFields(null);
        load();
    }, [load]);

    useEffect(() => {
        if (!canManage || roles !== null) return;

        api.get<PaginatedResponse<Role>>('/roles')
            .then((response) => setRoles(response.data.data))
            .catch(() => setRoles([]));
    }, [canManage, roles]);

    const usesOptions = (fieldType: string) => fieldType === 'single_select' || fieldType === 'multi_select';

    const submitCreate: FormEventHandler = (e) => {
        e.preventDefault();
        setSaving(true);
        setCreateErrors({});

        api.post(`/custom-fields/${entityType}`, {
            field_key: createForm.field_key,
            label: createForm.label,
            description: createForm.description || null,
            field_type: createForm.field_type,
            is_required: createForm.is_required,
            default_value: createForm.default_value || null,
            sensitivity: createForm.sensitivity,
            options: usesOptions(createForm.field_type) ? createForm.options : undefined,
        })
            .then(() => {
                setShowCreate(false);
                setCreateForm(emptyForm);
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setCreateErrors(apiError.errors ?? { field_key: [apiError.message] });
            })
            .finally(() => setSaving(false));
    };

    const startEdit = (field: CustomFieldDefinitionState) => {
        setEditingId(field.id);
        setEditErrors({});
        setRuleForm({ role_id: '', can_view: true, can_edit: false });
        setRuleErrors({});
        setEditForm({
            field_key: field.field_key,
            label: field.label,
            description: field.description ?? '',
            field_type: field.field_type,
            is_required: field.is_required,
            default_value: field.default_value ?? '',
            sensitivity: field.sensitivity,
            options: field.options.map((o) => ({ option_key: o.option_key, label: o.label, status: o.status })),
        });
    };

    const submitEdit = (field: CustomFieldDefinitionState) => {
        setSaving(true);
        setEditErrors({});

        api.patch(`/custom-fields/${field.id}`, {
            label: editForm.label,
            description: editForm.description || null,
            is_required: editForm.is_required,
            default_value: editForm.default_value || null,
            sensitivity: editForm.sensitivity,
            options: usesOptions(field.field_type) ? editForm.options : undefined,
        })
            .then(() => {
                setEditingId(null);
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setEditErrors(apiError.errors ?? { label: [apiError.message] });
            })
            .finally(() => setSaving(false));
    };

    const toggleStatus = (field: CustomFieldDefinitionState) => {
        setSaving(true);
        api.patch(`/custom-fields/${field.id}`, { status: field.status === 'active' ? 'inactive' : 'active' })
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const move = (field: CustomFieldDefinitionState, direction: -1 | 1) => {
        if (!fields) return;
        const sorted = [...fields].sort((a, b) => a.sort_order - b.sort_order);
        const index = sorted.findIndex((f) => f.id === field.id);
        const swapWith = sorted[index + direction];
        if (!swapWith) return;

        setSaving(true);
        Promise.all([
            api.patch(`/custom-fields/${field.id}`, { sort_order: swapWith.sort_order }),
            api.patch(`/custom-fields/${swapWith.id}`, { sort_order: field.sort_order }),
        ])
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setSaving(false));
    };

    const addOptionRow = (form: FieldFormState, setForm: (f: FieldFormState) => void) => {
        setForm({ ...form, options: [...form.options, { option_key: '', label: '', status: 'active' }] });
    };

    const submitAddRule = (field: CustomFieldDefinitionState) => {
        if (!ruleForm.role_id) return;
        setRuleSaving(true);
        setRuleErrors({});

        api.post(`/custom-fields/${field.id}/visibility-rules`, {
            role_id: Number(ruleForm.role_id),
            can_view: ruleForm.can_view,
            can_edit: ruleForm.can_edit,
        })
            .then(() => {
                setRuleForm({ role_id: '', can_view: true, can_edit: false });
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setRuleErrors(apiError.errors ?? { role_id: [apiError.message] });
            })
            .finally(() => setRuleSaving(false));
    };

    const updateRule = (rule: CustomFieldVisibilityRuleState, changes: Partial<Pick<CustomFieldVisibilityRuleState, 'can_view' | 'can_edit' | 'status'>>) => {
        setRuleSaving(true);
        api.patch(`/custom-field-visibility-rules/${rule.id}`, changes)
            .then(() => load())
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) setError(apiError);
            })
            .finally(() => setRuleSaving(false));
    };

    const sorted = fields ? [...fields].sort((a, b) => a.sort_order - b.sort_order) : null;

    return (
        <AppLayout>
            <Head title="Custom Fields" />

            <PageHeader
                title="Custom Fields"
                description="Tenant-defined fields for recruitment. Disabling a field preserves its existing values — it just stops appearing on the entity's own form."
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

            {sorted === null && !error && <LoadingState label="Loading custom fields…" />}

            {sorted !== null && (
                <div className="space-y-3">
                    {canManage && !showCreate && (
                        <Button type="button" onClick={() => setShowCreate(true)}>
                            Add field
                        </Button>
                    )}

                    {showCreate && (
                        <Card title="New custom field">
                            <form onSubmit={submitCreate} className="space-y-4">
                                <InputField
                                    label="Field key"
                                    name="field_key"
                                    value={createForm.field_key}
                                    onChange={(e) => setCreateForm({ ...createForm, field_key: e.target.value })}
                                    placeholder="visa_status"
                                    error={createErrors.field_key?.[0]}
                                    required
                                />
                                <p className="-mt-3 text-xs text-slate-500">
                                    Lowercase snake_case, cannot be changed later. Example: visa_status, referral_source.
                                </p>
                                <InputField
                                    label="Label"
                                    name="label"
                                    value={createForm.label}
                                    onChange={(e) => setCreateForm({ ...createForm, label: e.target.value })}
                                    error={createErrors.label?.[0]}
                                    required
                                />
                                <InputField
                                    label="Description"
                                    name="description"
                                    value={createForm.description}
                                    onChange={(e) => setCreateForm({ ...createForm, description: e.target.value })}
                                    error={createErrors.description?.[0]}
                                />
                                <SelectField
                                    label="Field type"
                                    name="field_type"
                                    value={createForm.field_type}
                                    onChange={(e) => setCreateForm({ ...createForm, field_type: e.target.value, options: [] })}
                                    error={createErrors.field_type?.[0]}
                                >
                                    {CUSTOM_FIELD_TYPES.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </SelectField>
                                <SelectField
                                    label="Sensitivity"
                                    name="sensitivity"
                                    value={createForm.sensitivity}
                                    onChange={(e) => setCreateForm({ ...createForm, sensitivity: e.target.value })}
                                >
                                    {CUSTOM_FIELD_SENSITIVITIES.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </SelectField>
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="checkbox"
                                        checked={createForm.is_required}
                                        onChange={(e) => setCreateForm({ ...createForm, is_required: e.target.checked })}
                                    />
                                    Required
                                </label>

                                {usesOptions(createForm.field_type) && (
                                    <div>
                                        <p className="text-sm font-medium text-slate-700">Options</p>
                                        {createForm.options.map((option, index) => (
                                            <div key={index} className="mt-2 flex gap-2">
                                                <input
                                                    className="w-1/3 rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                                                    placeholder="option_key"
                                                    value={option.option_key}
                                                    onChange={(e) => {
                                                        const options = [...createForm.options];
                                                        options[index] = { ...option, option_key: e.target.value };
                                                        setCreateForm({ ...createForm, options });
                                                    }}
                                                />
                                                <input
                                                    className="flex-1 rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                                                    placeholder="Label"
                                                    value={option.label}
                                                    onChange={(e) => {
                                                        const options = [...createForm.options];
                                                        options[index] = { ...option, label: e.target.value };
                                                        setCreateForm({ ...createForm, options });
                                                    }}
                                                />
                                            </div>
                                        ))}
                                        <Button type="button" variant="secondary" className="mt-2" onClick={() => addOptionRow(createForm, setCreateForm)}>
                                            Add option
                                        </Button>
                                        {createErrors.options && <p className="mt-1 text-sm text-red-600">{createErrors.options[0]}</p>}
                                    </div>
                                )}

                                <InputField
                                    label="Default value"
                                    name="default_value"
                                    value={createForm.default_value}
                                    onChange={(e) => setCreateForm({ ...createForm, default_value: e.target.value })}
                                    error={createErrors.default_value?.[0]}
                                />

                                <div>
                                    <p className="text-sm font-medium text-slate-700">Preview</p>
                                    <PreviewInput
                                        fieldType={createForm.field_type}
                                        defaultValue={createForm.default_value}
                                        options={createForm.options.map((o) => ({ ...o, sort_order: 0, status: o.status as 'active' | 'inactive' }))}
                                    />
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="secondary" onClick={() => { setShowCreate(false); setCreateForm(emptyForm); }}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={saving}>
                                        {saving ? 'Saving…' : 'Create field'}
                                    </Button>
                                </div>
                            </form>
                        </Card>
                    )}

                    {sorted.length === 0 && !showCreate && (
                        <p className="text-sm text-slate-500">No custom fields defined yet.</p>
                    )}

                    {sorted.map((field, index) => (
                        <Card key={field.id}>
                            {editingId === field.id ? (
                                <div className="space-y-4">
                                    <InputField
                                        label="Label"
                                        name={`edit-label-${field.id}`}
                                        value={editForm.label}
                                        onChange={(e) => setEditForm({ ...editForm, label: e.target.value })}
                                        error={editErrors.label?.[0]}
                                    />
                                    <InputField
                                        label="Description"
                                        name={`edit-description-${field.id}`}
                                        value={editForm.description}
                                        onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}
                                    />
                                    <label className="flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={editForm.is_required}
                                            onChange={(e) => setEditForm({ ...editForm, is_required: e.target.checked })}
                                        />
                                        Required
                                    </label>
                                    {usesOptions(field.field_type) && (
                                        <div>
                                            <p className="text-sm font-medium text-slate-700">Options</p>
                                            {editForm.options.map((option, optIndex) => (
                                                <div key={option.option_key || optIndex} className="mt-2 flex items-center gap-2">
                                                    <input
                                                        className="w-1/3 rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 disabled:bg-slate-100"
                                                        value={option.option_key}
                                                        disabled={option.option_key !== ''}
                                                        placeholder="option_key"
                                                        onChange={(e) => {
                                                            const options = [...editForm.options];
                                                            options[optIndex] = { ...option, option_key: e.target.value };
                                                            setEditForm({ ...editForm, options });
                                                        }}
                                                    />
                                                    <input
                                                        className="flex-1 rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                                                        value={option.label}
                                                        onChange={(e) => {
                                                            const options = [...editForm.options];
                                                            options[optIndex] = { ...option, label: e.target.value };
                                                            setEditForm({ ...editForm, options });
                                                        }}
                                                    />
                                                    <label className="flex items-center gap-1 text-xs text-slate-600">
                                                        <input
                                                            type="checkbox"
                                                            checked={option.status === 'active'}
                                                            onChange={(e) => {
                                                                const options = [...editForm.options];
                                                                options[optIndex] = { ...option, status: e.target.checked ? 'active' : 'inactive' };
                                                                setEditForm({ ...editForm, options });
                                                            }}
                                                        />
                                                        Active
                                                    </label>
                                                </div>
                                            ))}
                                            <Button type="button" variant="secondary" className="mt-2" onClick={() => addOptionRow(editForm, setEditForm)}>
                                                Add option
                                            </Button>
                                        </div>
                                    )}
                                    <div className="flex justify-end gap-2">
                                        <Button type="button" variant="secondary" onClick={() => setEditingId(null)}>
                                            Cancel
                                        </Button>
                                        <Button type="button" onClick={() => submitEdit(field)} disabled={saving}>
                                            {saving ? 'Saving…' : 'Save'}
                                        </Button>
                                    </div>

                                    <div className="border-t border-slate-200 pt-4">
                                        <p className="text-sm font-medium text-slate-700">Visibility rules</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Grants a role view/edit access beyond the default sensitivity tier, or makes the field
                                            read-only or fully hidden for that role. A user still needs the underlying entity
                                            permission — a rule never grants access on its own.
                                        </p>

                                        {field.visibility_rules.length === 0 && (
                                            <p className="mt-2 text-sm text-slate-500">No visibility rules for this field.</p>
                                        )}

                                        {field.visibility_rules.map((rule) => (
                                            <div key={rule.id} className="mt-2 flex flex-wrap items-center gap-3 rounded-md bg-slate-50 px-3 py-2">
                                                <span className="text-sm font-medium text-slate-800">{rule.role.name}</span>
                                                <Badge tone={rule.status === 'active' ? 'success' : 'neutral'}>
                                                    {rule.status === 'active' ? 'Active' : 'Disabled'}
                                                </Badge>
                                                <label className="flex items-center gap-1 text-xs text-slate-600">
                                                    <input
                                                        type="checkbox"
                                                        checked={rule.can_view}
                                                        disabled={ruleSaving}
                                                        onChange={(e) =>
                                                            updateRule(rule, {
                                                                can_view: e.target.checked,
                                                                can_edit: e.target.checked ? rule.can_edit : false,
                                                            })
                                                        }
                                                    />
                                                    Can view
                                                </label>
                                                <label className="flex items-center gap-1 text-xs text-slate-600">
                                                    <input
                                                        type="checkbox"
                                                        checked={rule.can_edit}
                                                        disabled={ruleSaving || !rule.can_view}
                                                        onChange={(e) => updateRule(rule, { can_edit: e.target.checked })}
                                                    />
                                                    Can edit
                                                </label>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    className="ml-auto"
                                                    disabled={ruleSaving}
                                                    onClick={() => updateRule(rule, { status: rule.status === 'active' ? 'inactive' : 'active' })}
                                                >
                                                    {rule.status === 'active' ? 'Disable' : 'Enable'}
                                                </Button>
                                            </div>
                                        ))}

                                        <div className="mt-3 flex flex-wrap items-end gap-3">
                                            <SelectField
                                                label="Add rule for role"
                                                name={`rule-role-${field.id}`}
                                                value={ruleForm.role_id}
                                                onChange={(e) => setRuleForm({ ...ruleForm, role_id: e.target.value })}
                                                error={ruleErrors.role_id?.[0]}
                                            >
                                                <option value="">Select a role…</option>
                                                {(roles ?? [])
                                                    .filter((role) => role.slug !== 'tenant-admin')
                                                    .filter((role) => !field.visibility_rules.some((rule) => rule.role.id === role.id))
                                                    .map((role) => (
                                                        <option key={role.id} value={role.id}>
                                                            {role.name}
                                                        </option>
                                                    ))}
                                            </SelectField>
                                            <label className="flex items-center gap-1 text-xs text-slate-600">
                                                <input
                                                    type="checkbox"
                                                    checked={ruleForm.can_view}
                                                    onChange={(e) =>
                                                        setRuleForm({
                                                            ...ruleForm,
                                                            can_view: e.target.checked,
                                                            can_edit: e.target.checked ? ruleForm.can_edit : false,
                                                        })
                                                    }
                                                />
                                                Can view
                                            </label>
                                            <label className="flex items-center gap-1 text-xs text-slate-600">
                                                <input
                                                    type="checkbox"
                                                    checked={ruleForm.can_edit}
                                                    disabled={!ruleForm.can_view}
                                                    onChange={(e) => setRuleForm({ ...ruleForm, can_edit: e.target.checked })}
                                                />
                                                Can edit
                                            </label>
                                            <Button type="button" variant="secondary" disabled={ruleSaving || !ruleForm.role_id} onClick={() => submitAddRule(field)}>
                                                {ruleSaving ? 'Saving…' : 'Add rule'}
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium text-slate-900">{field.label}</p>
                                            <span className="text-xs text-slate-400">({field.field_key})</span>
                                            <Badge tone={field.status === 'active' ? 'success' : 'neutral'}>
                                                {field.status === 'active' ? 'Active' : 'Disabled'}
                                            </Badge>
                                            {field.is_required && <Badge tone="warning">Required</Badge>}
                                            {field.sensitivity !== 'normal' && <Badge tone="warning">{field.sensitivity}</Badge>}
                                        </div>
                                        <p className="mt-1 text-sm text-slate-500">{field.description}</p>
                                        <p className="mt-1 text-xs text-slate-400">Type: {field.field_type}</p>
                                    </div>
                                    {canManage && (
                                        <div className="flex shrink-0 flex-col items-end gap-2">
                                            <div className="flex gap-1">
                                                <Button type="button" variant="secondary" onClick={() => move(field, -1)} disabled={index === 0 || saving}>
                                                    ↑
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => move(field, 1)} disabled={index === sorted.length - 1 || saving}>
                                                    ↓
                                                </Button>
                                            </div>
                                            <div className="flex gap-2">
                                                <Button type="button" variant="secondary" onClick={() => startEdit(field)}>
                                                    Edit
                                                </Button>
                                                <Button type="button" variant="secondary" onClick={() => toggleStatus(field)} disabled={saving}>
                                                    {field.status === 'active' ? 'Disable' : 'Enable'}
                                                </Button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </Card>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
