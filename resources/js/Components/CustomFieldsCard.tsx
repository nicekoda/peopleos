import { useEffect, useState } from 'react';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
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
 */
export default function CustomFieldsCard({
    title,
    entityTypeUrl,
    endpointUrl,
    payloadKey,
    values,
    onSaved,
}: {
    title: string;
    entityTypeUrl: string;
    endpointUrl: string;
    payloadKey: string;
    values: Record<string, unknown> | undefined;
    onSaved: () => void;
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
        // Only ever submit fields the caller can actually edit — never
        // resubmit a read-only field's displayed value, even though the
        // backend would reject it anyway (403) if we did.
        (defs ?? []).filter((field) => field.can_edit).forEach((field) => {
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

    if (defs === null || defs.length === 0) {
        return null;
    }

    return (
        <Card title={title}>
            <div className="space-y-3">
                {defs.map((field) => (
                    <div key={field.field_key}>
                        <label className="block text-sm font-medium text-slate-700">
                            {field.label} {field.is_required && <span className="text-red-500">*</span>}
                        </label>
                        {field.can_edit ? (
                            field.field_type === 'single_select' ? (
                                <select
                                    className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300"
                                    value={draft[field.field_key] ?? ''}
                                    onChange={(e) => setDraft({ ...draft, [field.field_key]: e.target.value })}
                                >
                                    <option value="">— None —</option>
                                    {field.options.filter((o) => o.status === 'active').map((option) => (
                                        <option key={option.option_key} value={option.option_key}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            ) : field.field_type === 'boolean' ? (
                                <input
                                    type="checkbox"
                                    className="mt-1 block h-4 w-4"
                                    checked={draft[field.field_key] === 'true'}
                                    onChange={(e) => setDraft({ ...draft, [field.field_key]: e.target.checked ? 'true' : 'false' })}
                                />
                            ) : (
                                <input
                                    type={field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : field.field_type === 'email' ? 'email' : field.field_type === 'url' ? 'url' : 'text'}
                                    className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300"
                                    value={draft[field.field_key] ?? ''}
                                    onChange={(e) => setDraft({ ...draft, [field.field_key]: e.target.value })}
                                />
                            )
                        ) : (
                            <p className="mt-1 text-sm text-slate-900">{draft[field.field_key] || '—'}</p>
                        )}
                        <ErrorMessage message={errors[`${payloadKey}.${field.field_key}`]?.[0]} />
                    </div>
                ))}
                {defs.some((field) => field.can_edit) && (
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
