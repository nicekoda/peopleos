export type CustomFieldType =
    | 'text'
    | 'textarea'
    | 'number'
    | 'date'
    | 'boolean'
    | 'single_select'
    | 'multi_select'
    | 'email'
    | 'url';

export type CustomFieldSensitivity = 'normal' | 'sensitive' | 'confidential' | 'restricted';

export type CustomFieldStatus = 'active' | 'inactive';

export interface CustomFieldOptionState {
    option_key: string;
    label: string;
    sort_order: number;
    status: CustomFieldStatus;
}

export interface CustomFieldValidationRuleState {
    rule_key: string;
    rule_value: string | null;
}

export interface CustomFieldDefinitionState {
    id: string;
    entity_type: string;
    field_key: string;
    label: string;
    description: string | null;
    field_type: CustomFieldType;
    is_required: boolean;
    default_value: string | null;
    sensitivity: CustomFieldSensitivity;
    sort_order: number;
    status: CustomFieldStatus;
    // Checkpoint 50 — computed fresh per request against the current
    // caller (parent entity permission + sensitivity-tier permission
    // combined); never a stored value. Backend remains the security
    // boundary — these only drive what the frontend renders.
    can_view: boolean;
    can_edit: boolean;
    options: CustomFieldOptionState[];
    validation_rules: CustomFieldValidationRuleState[];
}

export const CUSTOM_FIELD_TYPES: { value: CustomFieldType; label: string; usesOptions: boolean }[] = [
    { value: 'text', label: 'Text', usesOptions: false },
    { value: 'textarea', label: 'Multi-line Text', usesOptions: false },
    { value: 'number', label: 'Number', usesOptions: false },
    { value: 'date', label: 'Date', usesOptions: false },
    { value: 'boolean', label: 'Yes/No', usesOptions: false },
    { value: 'single_select', label: 'Single Select', usesOptions: true },
    { value: 'multi_select', label: 'Multi Select', usesOptions: true },
    { value: 'email', label: 'Email', usesOptions: false },
    { value: 'url', label: 'URL', usesOptions: false },
];

export const CUSTOM_FIELD_SENSITIVITIES: { value: CustomFieldSensitivity; label: string }[] = [
    { value: 'normal', label: 'Normal' },
    { value: 'sensitive', label: 'Sensitive' },
    { value: 'confidential', label: 'Confidential' },
    { value: 'restricted', label: 'Restricted' },
];
