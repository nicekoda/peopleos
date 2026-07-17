<?php

namespace App\Enums;

use Carbon\Carbon;

/**
 * Checkpoint 48 — the fixed, backend-owned validation rule catalog.
 * `rule_value` is always a plain scalar (a number or a date literal),
 * never a tenant-supplied regex, PHP snippet, SQL fragment, or
 * expression — `passes()` is the only code ever executed, and it's
 * fixed per case, not tenant-authored.
 */
enum CustomFieldValidationRuleKey: string
{
    case MinLength = 'min_length';
    case MaxLength = 'max_length';
    case MinValue = 'min_value';
    case MaxValue = 'max_value';
    case DateBefore = 'date_before';
    case DateAfter = 'date_after';
    case EmailFormat = 'email_format';
    case UrlFormat = 'url_format';

    public function label(): string
    {
        return match ($this) {
            self::MinLength => 'Minimum length',
            self::MaxLength => 'Maximum length',
            self::MinValue => 'Minimum value',
            self::MaxValue => 'Maximum value',
            self::DateBefore => 'Date before',
            self::DateAfter => 'Date after',
            self::EmailFormat => 'Valid email format',
            self::UrlFormat => 'Valid URL format',
        };
    }

    /**
     * Whether this rule's `rule_value` is a required, non-empty scalar
     * (min_length/max_length/min_value/max_value/date_before/date_after)
     * versus a self-contained check with no comparison value
     * (email_format/url_format).
     */
    public function requiresRuleValue(): bool
    {
        return match ($this) {
            self::EmailFormat, self::UrlFormat => false,
            default => true,
        };
    }

    /**
     * @param  mixed  $value  Already type-coerced per the field's CustomFieldType.
     */
    public function passes(mixed $value, ?string $ruleValue): bool
    {
        if ($value === null) {
            // Absence is is_required's concern, not a validation rule's.
            return true;
        }

        return match ($this) {
            self::MinLength => mb_strlen((string) $value) >= (int) $ruleValue,
            self::MaxLength => mb_strlen((string) $value) <= (int) $ruleValue,
            self::MinValue => (float) $value >= (float) $ruleValue,
            self::MaxValue => (float) $value <= (float) $ruleValue,
            self::DateBefore => Carbon::parse((string) $value)->lt(Carbon::parse((string) $ruleValue)),
            self::DateAfter => Carbon::parse((string) $value)->gt(Carbon::parse((string) $ruleValue)),
            self::EmailFormat => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false,
            self::UrlFormat => filter_var((string) $value, FILTER_VALIDATE_URL) !== false,
        };
    }
}
