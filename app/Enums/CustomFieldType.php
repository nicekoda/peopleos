<?php

namespace App\Enums;

/**
 * Checkpoint 48 — the fixed, backend-approved set of custom field types.
 * No custom code, no arbitrary type, nothing tenant-defined — a tenant
 * always picks one of these cases, never supplies a type string that
 * isn't already here.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case Email = 'email';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Textarea => 'Multi-line Text',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Boolean => 'Yes/No',
            self::SingleSelect => 'Single Select',
            self::MultiSelect => 'Multi Select',
            self::Email => 'Email',
            self::Url => 'URL',
        };
    }

    public function usesOptions(): bool
    {
        return in_array($this, [self::SingleSelect, self::MultiSelect], true);
    }

    /**
     * Which `custom_field_values` column this type's value is stored in —
     * the one place the type determines physical storage shape.
     */
    public function storageColumn(): string
    {
        return match ($this) {
            self::Text, self::Textarea, self::Email, self::Url, self::SingleSelect => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
            self::MultiSelect => 'value_json',
        };
    }

    /**
     * Which validation rule keys are ever applicable to this type — used
     * to reject e.g. `min_value` on a `text` field at definition time.
     *
     * @return list<CustomFieldValidationRuleKey>
     */
    public function allowedValidationRuleKeys(): array
    {
        return match ($this) {
            self::Text, self::Textarea => [
                CustomFieldValidationRuleKey::MinLength,
                CustomFieldValidationRuleKey::MaxLength,
            ],
            self::Number => [
                CustomFieldValidationRuleKey::MinValue,
                CustomFieldValidationRuleKey::MaxValue,
            ],
            self::Date => [
                CustomFieldValidationRuleKey::DateBefore,
                CustomFieldValidationRuleKey::DateAfter,
            ],
            self::Email => [CustomFieldValidationRuleKey::EmailFormat],
            self::Url => [CustomFieldValidationRuleKey::UrlFormat],
            self::Boolean, self::SingleSelect, self::MultiSelect => [],
        };
    }
}
