import { useEffect, useState } from 'react';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import CustomFieldInput from '@/Components/CustomFieldInput';
import { api, toApiError, redirectIfUnauthenticated } from '@/lib/api';
import { CustomFieldDefinitionState } from '@/types/customField';

/**
 * Checkpoint 48/49/50 — originally built inline in ApplicationShow.tsx
 * for the two recruitment entities (recruitment_applicant,
 * job_application), extracted in Checkpoint 51 so Employee (entity #3)
 * can reuse the exact same editor rather than a third copy of this
 * logic. Generalised via `endpointUrl`/`entityTypeUrl`/`payloadKey`
 * instead of assuming a `/job-applications/{id}` endpoint — recruitment
 * usage is unchanged (still passes its two payload keys), Employee
 * usage passes its own endpoint and a single `custom_field_values` key
 * (no sibling-entity collision risk there, unlike job_application's own
 * applicant).
 *
 * Checkpoint 50 — a field the caller cannot view at all
 * (`can_view: false`) is filtered out entirely, never rendered even as
 * a locked placeholder — matching the backend's own "omit the key"
 * behavior for the same reason. A field the caller can view but not
 * edit (`can_view: true, can_edit: false`) renders read-only. This is
 * UX only — the frontend never receives a value it can't view in the
 * first place; the backend (CustomFieldValueService) is what actually
 * enforces this.
 *
 * Checkpoint 52 — per-field-type input rendering is now the shared
 * CustomFieldInput component, also used by CustomFormRenderer, rather
 * than a switch duplicated in both places.
 *
 * Checkpoint 54 — gained an optional `excludeFieldKeys` prop so a page
 * that also renders CustomFormRenderer can use this purely as a
 * fallback for fields not assigned to any active form/section/field
 * row, instead of rendering every viewable field unconditionally. This
 * is a rendering-location decision only — the `status === 'active' &&
 * can_view` filter below is unchanged and remains the actual (server-
 * confirmed) access gate; `excludeFieldKeys` never widens what this
 * component would otherwise show, only narrows it further for UX.
 */
export default function CustomFieldsCard({
    title,
    entityTypeUrl,
    endpointUrl,
    payloadKey,
    values,
    onSaved,
    excludeFieldKeys,
}: {
    title: string;
    entityTypeUrl: string;
    endpointUrl: string;
    payloadKey: string;
    values: Record<string, unknown> | undefined;
    onSaved: () => void;
    excludeFieldKeys?: Set<string>;
}) {
    const [defs, setDefs] = useState<CustomFieldDefinitionState[] | null>(null);
    const [draft, setDraft] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});

    useEffect(() => {
        api.get<{ data: CustomFieldDefinitionState[] }>(`/custom-fields/${entityTypeUrl}`)
            .then((response) => setDefs(response.data.data.filter((field) => field.status === 'active' && field.can_view)))
            .catch(() => {
                // Non-fatal — the rest of the page still works if this
                // fails (e.g. the viewer lacks custom_fields.view); the
                // card simply doesn't render.
            });
    }, [entityTypeUrl]);

    const visibleDefs = (defs ?? []).filter((field) => !excludeFieldKeys?.has(field.field_key));

    useEffect(() => {
        const nextDraft: Record<string, string> = {};
        Object.entries(values ?? {}).forEach(([key, value]) => {
            nextDraft[key] = Array.isArray(value) ? value.join(',') : value === null || value === undefined ? '' : String(value);
        });
        setDraft(nextDraft);
    }, [values]);

    const submit = () => {
        setSaving(true);
        setErrors({});

        const payload: Record<string, unknown> = {};
        // Only ever submit fields this card actually renders (i.e. not
        // excluded as form-assigned) that the caller can also edit —
        // never resubmit a read-only field's displayed value, even
        // though the backend would reject it anyway (403) if we did.
        visibleDefs.filter((field) => field.can_edit).forEach((field) => {
            const raw = draft[field.field_key] ?? '';
            if (field.field_type === 'multi_select') {
                payload[field.field_key] = raw === '' ? [] : raw.split(',').map((v) => v.trim()).filter(Boolean);
            } else if (field.field_type === 'boolean') {
                payload[field.field_key] = raw === 'true';
            } else {
                payload[field.field_key] = raw === '' ? null : raw;
            }
        });

        api.patch(endpointUrl, { [payloadKey]: payload })
            .then(() => onSaved())
            .catch((err) => {
                const apiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) return;
                setErrors(apiError.errors ?? {});
            })
            .finally(() => setSaving(false));
    };

    if (defs === null || visibleDefs.length === 0) {
        return null;
    }

    return (
        <Card title={title}>
            <div className="space-y-3">
                {visibleDefs.map((field) => (
                    <div key={field.field_key}>
                        <label className="block text-sm font-medium text-slate-700">
                            {field.label} {field.is_required && <span className="text-red-500">*</span>}
                        </label>
                        <CustomFieldInput
                            field={field}
                            value={draft[field.field_key] ?? ''}
                            onChange={(value) => setDraft({ ...draft, [field.field_key]: value })}
                            canEdit={field.can_edit}
                        />
                        <ErrorMessage message={errors[`${payloadKey}.${field.field_key}`]?.[0]} />
                    </div>
                ))}
                {visibleDefs.some((field) => field.can_edit) && (
                    <div className="flex justify-end">
                        <Button type="button" onClick={submit} disabled={saving}>
                            {saving ? 'Saving…' : `Save ${title.toLowerCase()}`}
                        </Button>
                    </div>
                )}
            </div>
        </Card>
    );
}
