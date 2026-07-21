import { useEffect, useState } from 'react';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import CustomFieldInput from '@/Components/CustomFieldInput';
import { api, toApiError, redirectIfUnauthenticated } from '@/lib/api';
import { CustomFormState } from '@/types/customForm';

/**
 * Checkpoint 52 — renders every active Custom Form defined for an
 * entity, each as its own independently-submittable card (mirroring
 * CustomFieldsCard's own "one card per unit of data" shape). A form is
 * metadata only: submitting still means PATCH `endpointUrl` with
 * `custom_field_values` scoped to this form's own field keys — the
 * exact same call CustomFieldsCard already makes, never a new
 * write path.
 *
 * Checkpoint 54 — no longer self-fetches. The parent page (Employees/
 * Show.tsx) fetches `GET /custom-forms/{entityType}` once and passes
 * the raw result down as `forms`, so it can derive `assignedFieldKeys`
 * from the exact same data this component renders from, with no risk
 * of the two views disagreeing about what "active" means. Deliberately
 * no longer rendered unconditionally alongside CustomFieldsCard — the
 * parent now excludes assigned fields from the fallback card instead,
 * closing the Checkpoint 52 MVP overlap now that Checkpoint 53 has
 * proven visibility rules work identically through both surfaces.
 *
 * Inactive forms/sections/field-rows are filtered out here,
 * client-side — the backend returns everything (active and inactive)
 * so Settings can manage disabled ones, the same split CustomFieldsCard
 * already has for definitions. A form-field row's own status was
 * previously never filtered here at all (a real gap — see
 * docs/architecture.md), meaning a disabled row kept rendering.
 * Fields the viewer cannot see, or whose underlying custom field is
 * disabled, are never present in the API response at all
 * (server-enforced — see CustomFormSectionResource).
 */
export default function CustomFormRenderer({
    forms,
    endpointUrl,
    values,
    onSaved,
}: {
    forms: CustomFormState[] | null;
    endpointUrl: string;
    values: Record<string, unknown> | undefined;
    onSaved: () => void;
}) {
    const activeForms = (forms ?? []).filter((form) => form.status === 'active');

    if (forms === null || activeForms.length === 0) {
        return null;
    }

    return (
        <>
            {activeForms.map((form) => (
                <CustomFormCard key={form.id} form={form} endpointUrl={endpointUrl} values={values} onSaved={onSaved} />
            ))}
        </>
    );
}

function CustomFormCard({
    form,
    endpointUrl,
    values,
    onSaved,
}: {
    form: CustomFormState;
    endpointUrl: string;
    values: Record<string, unknown> | undefined;
    onSaved: () => void;
}) {
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        const nextDraft: Record<string, string> = {};
        Object.entries(values ?? {}).forEach(([key, value]) => {
            nextDraft[key] = Array.isArray(value) ? value.join(',') : value === null || value === undefined ? '' : String(value);
        });
        setDraft(nextDraft);
    }, [values]);

    const sections = [...form.sections].filter((s) => s.status === 'active').sort((a, b) => a.sort_order - b.sort_order);
    // A form-field row's own status was never filtered here before
    // Checkpoint 54 — the backend intentionally returns disabled rows
    // too (Settings > Custom Forms needs them for its Restore button),
    // so this client-side filter is what actually hides a disabled row
    // from the rendered form.
    const allFields = sections.flatMap((section) => [...section.fields].filter((f) => f.status === 'active').sort((a, b) => a.sort_order - b.sort_order));
    const anyEditable = allFields.some((field) => field.custom_field_definition.can_edit);

    const submit = () => {
        setSaving(true);
        setErrors({});

        const payload: Record<string, unknown> = {};
        // Only ever submit fields this form's own sections actually
        // contain, and only ones the caller can edit — never resubmit a
        // read-only field's displayed value, even though the backend
        // would reject it anyway (403) if we did.
        allFields.filter((field) => field.custom_field_definition.can_edit).forEach((field) => {
            const definition = field.custom_field_definition;
            const raw = draft[definition.field_key] ?? '';
            if (definition.field_type === 'multi_select') {
                payload[definition.field_key] = raw === '' ? [] : raw.split(',').map((v) => v.trim()).filter(Boolean);
            } else if (definition.field_type === 'boolean') {
                payload[definition.field_key] = raw === 'true';
            } else {
                payload[definition.field_key] = raw === '' ? null : raw;
            }
        });

        api.patch(endpointUrl, { custom_field_values: payload })
            .then(() => onSaved())
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setErrors(apiError.errors ?? {});
            })
            .finally(() => setSaving(false));
    };

    if (sections.length === 0) {
        return null;
    }

    return (
        <Card title={form.name}>
            <div className="space-y-5">
                {form.description && <p className="-mt-1 text-sm text-slate-500">{form.description}</p>}
                {sections.map((section) => {
                    const sortedFields = [...section.fields].filter((f) => f.status === 'active').sort((a, b) => a.sort_order - b.sort_order);
                    if (sortedFields.length === 0) return null;

                    return (
                        <div key={section.id}>
                            <p className="text-sm font-semibold text-slate-800">{section.title}</p>
                            {section.description && <p className="mt-0.5 text-xs text-slate-500">{section.description}</p>}
                            <div className="mt-2 space-y-3">
                                {sortedFields.map((field) => {
                                    const definition = field.custom_field_definition;
                                    const label = field.label_override ?? definition.label;
                                    const isRequired = field.is_required_override ?? definition.is_required;

                                    return (
                                        <div key={field.id}>
                                            <label className="block text-sm font-medium text-slate-700">
                                                {label} {isRequired && <span className="text-red-500">*</span>}
                                            </label>
                                            <CustomFieldInput
                                                field={definition}
                                                value={draft[definition.field_key] ?? ''}
                                                onChange={(value) => setDraft({ ...draft, [definition.field_key]: value })}
                                                canEdit={definition.can_edit}
                                            />
                                            {field.help_text && <p className="mt-1 text-xs text-slate-400">{field.help_text}</p>}
                                            <ErrorMessage message={errors[`custom_field_values.${definition.field_key}`]?.[0]} />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
                {anyEditable && (
                    <div className="flex justify-end">
                        <Button type="button" onClick={submit} disabled={saving}>
                            {saving ? 'Saving…' : `Save ${form.name.toLowerCase()}`}
                        </Button>
                    </div>
                )}
            </div>
        </Card>
    );
}
