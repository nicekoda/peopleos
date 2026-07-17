<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldDefinitionStatus;
use App\Enums\CustomFieldType;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldOption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checkpoint 48 — the single point where a raw custom field value is
 * validated and coerced to its typed storage shape. Only ever applies
 * the fixed CustomFieldValidationRuleKey catalog (see that enum's own
 * docblock) — never anything tenant-supplied beyond a plain scalar
 * `rule_value`.
 */
class CustomFieldValueValidator
{
    /**
     * @return array{column: string, value: mixed}|array{column: string, value: null}
     *
     * @throws ValidationException
     */
    public static function validate(CustomFieldDefinition $definition, mixed $rawValue, bool $enforceActiveOptions = true): array
    {
        $column = $definition->field_type->storageColumn();

        if ($rawValue === null || $rawValue === '' || $rawValue === []) {
            if ($definition->is_required) {
                throw self::fail($definition, 'This field is required.');
            }

            return ['column' => $column, 'value' => null];
        }

        $typed = match ($definition->field_type) {
            CustomFieldType::Text, CustomFieldType::Textarea => self::asString($definition, $rawValue),
            CustomFieldType::Email => self::asEmail($definition, $rawValue),
            CustomFieldType::Url => self::asUrl($definition, $rawValue),
            CustomFieldType::Number => self::asNumber($definition, $rawValue),
            CustomFieldType::Date => self::asDate($definition, $rawValue),
            CustomFieldType::Boolean => self::asBoolean($definition, $rawValue),
            CustomFieldType::SingleSelect => self::asSingleSelect($definition, $rawValue, $enforceActiveOptions),
            CustomFieldType::MultiSelect => self::asMultiSelect($definition, $rawValue, $enforceActiveOptions),
        };

        foreach ($definition->validationRules as $rule) {
            if (! $rule->rule_key->passes($typed, $rule->rule_value)) {
                throw self::fail($definition, "The {$definition->label} field failed its '{$rule->rule_key->label()}' rule.");
            }
        }

        return ['column' => $column, 'value' => $typed];
    }

    private static function asString(CustomFieldDefinition $definition, mixed $value): string
    {
        if (! is_string($value)) {
            throw self::fail($definition, 'This field must be text.');
        }

        return $value;
    }

    private static function asEmail(CustomFieldDefinition $definition, mixed $value): string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw self::fail($definition, 'This field must be a valid email address.');
        }

        return $value;
    }

    private static function asUrl(CustomFieldDefinition $definition, mixed $value): string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw self::fail($definition, 'This field must be a valid URL.');
        }

        return $value;
    }

    private static function asNumber(CustomFieldDefinition $definition, mixed $value): float
    {
        if (! is_numeric($value)) {
            throw self::fail($definition, 'This field must be a number.');
        }

        return (float) $value;
    }

    private static function asDate(CustomFieldDefinition $definition, mixed $value): string
    {
        if (! is_string($value)) {
            throw self::fail($definition, 'This field must be a valid date.');
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw self::fail($definition, 'This field must be a valid date.');
        }
    }

    private static function asBoolean(CustomFieldDefinition $definition, mixed $value): bool
    {
        if (! is_bool($value)) {
            throw self::fail($definition, 'This field must be true or false.');
        }

        return $value;
    }

    private static function asSingleSelect(CustomFieldDefinition $definition, mixed $value, bool $enforceActiveOptions): string
    {
        if (! is_string($value)) {
            throw self::fail($definition, 'This field must be one of the allowed options.');
        }

        $options = self::activeOnlyIfRequired($definition, $enforceActiveOptions);

        if (! $options->contains('option_key', $value)) {
            throw self::fail($definition, 'This field must be one of the allowed options.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function asMultiSelect(CustomFieldDefinition $definition, mixed $value, bool $enforceActiveOptions): array
    {
        if (! is_array($value) || count(array_filter($value, fn ($item) => ! is_string($item))) > 0) {
            throw self::fail($definition, 'This field must be a list of allowed options.');
        }

        $validKeys = self::activeOnlyIfRequired($definition, $enforceActiveOptions)->pluck('option_key')->all();

        foreach ($value as $optionKey) {
            if (! in_array($optionKey, $validKeys, true)) {
                throw self::fail($definition, 'This field must be a list of allowed options.');
            }
        }

        return array_values(array_unique($value));
    }

    /**
     * @return Collection<int, CustomFieldOption>
     */
    private static function activeOnlyIfRequired(CustomFieldDefinition $definition, bool $enforceActiveOptions): Collection
    {
        return $enforceActiveOptions
            ? $definition->options->filter(fn ($option) => $option->status === CustomFieldDefinitionStatus::Active)->values()
            : $definition->options;
    }

    private static function fail(CustomFieldDefinition $definition, string $message): ValidationException
    {
        return ValidationException::withMessages([$definition->field_key => [$message]]);
    }
}
