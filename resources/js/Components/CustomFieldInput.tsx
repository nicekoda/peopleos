import { CustomFieldDefinitionState } from '@/types/customField';

/**
 * Checkpoint 52 — extracted from CustomFieldsCard's own render switch so
 * CustomFormRenderer can reuse the exact same per-field-type input
 * rendering rather than a third copy of it. Purely a rendering
 * primitive: `canEdit` decides editable-input vs. read-only display,
 * matching the backend's own can_view/can_edit split — this component
 * never itself decides whether a field should render at all (that's
 * the caller's job, same "omit means omit" rule already established
 * for values).
 */
export default function CustomFieldInput({
    field,
    value,
    onChange,
    canEdit,
}: {
    field: CustomFieldDefinitionState;
    value: string;
    onChange: (value: string) => void;
    canEdit: boolean;
}) {
    if (!canEdit) {
        return <p className="mt-1 text-sm text-slate-900">{value || '—'}</p>;
    }

    if (field.field_type === 'single_select') {
        return (
            <select
                className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">— None —</option>
                {field.options.filter((o) => o.status === 'active').map((option) => (
                    <option key={option.option_key} value={option.option_key}>
                        {option.label}
                    </option>
                ))}
            </select>
        );
    }

    if (field.field_type === 'boolean') {
        return (
            <input
                type="checkbox"
                className="mt-1 block h-4 w-4"
                checked={value === 'true'}
                onChange={(e) => onChange(e.target.checked ? 'true' : 'false')}
            />
        );
    }

    const inputType = field.field_type === 'number' ? 'number' : field.field_type === 'date' ? 'date' : field.field_type === 'email' ? 'email' : field.field_type === 'url' ? 'url' : 'text';

    return (
        <input
            type={inputType}
            className="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm shadow-sm ring-1 ring-inset ring-slate-300"
            value={value}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}
