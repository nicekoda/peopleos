import { CustomFieldDefinitionState } from '@/types/customField';

export type CustomFormStatus = 'active' | 'inactive';

/**
 * Checkpoint 52 — mirrors App\Http\Resources\CustomFormResource/
 * CustomFormSectionResource/CustomFormFieldResource exactly. A field
 * whose underlying custom field is disabled or that the viewer lacks
 * can_view for is never present in `fields` at all — the backend omits
 * it, this type never needs an "is this hidden" flag.
 */
export interface CustomFormFieldState {
    id: string;
    label_override: string | null;
    help_text: string | null;
    placeholder: string | null;
    // UI-only (Checkpoint 52) — never enforced server-side beyond the
    // underlying custom field's own is_required.
    is_required_override: boolean | null;
    sort_order: number;
    status: CustomFormStatus;
    custom_field_definition: CustomFieldDefinitionState;
    created_at: string | null;
    updated_at: string | null;
}

export interface CustomFormSectionState {
    id: string;
    section_key: string;
    title: string;
    description: string | null;
    sort_order: number;
    status: CustomFormStatus;
    fields: CustomFormFieldState[];
    created_at: string | null;
    updated_at: string | null;
}

export interface CustomFormState {
    id: string;
    entity_type: string;
    form_key: string;
    name: string;
    description: string | null;
    status: CustomFormStatus;
    sort_order: number;
    sections: CustomFormSectionState[];
    created_at: string | null;
    updated_at: string | null;
}
