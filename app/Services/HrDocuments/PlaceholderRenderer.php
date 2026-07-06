<?php

namespace App\Services\HrDocuments;

use App\Models\Employee;
use App\Models\Tenant;

/**
 * Checkpoint 34 — strict allowlist placeholder substitution for HR
 * document templates. Deliberately NOT a template engine: no Blade
 * compilation, no eval, no reflection/property access driven by the
 * stored template string, no arbitrary method calls. `strtr()`'s array
 * form does a single, simultaneous pass over the input using only the
 * keys below — an unknown `{{...}}` token is never matched, so it passes
 * through completely unchanged (never executed, never an error). See
 * docs/security.md for the full reasoning.
 */
class PlaceholderRenderer
{
    /**
     * @var list<string>
     */
    public const ALLOWED_PLACEHOLDERS = [
        '{{employee.name}}',
        '{{employee.employee_number}}',
        '{{employee.email}}',
        '{{employee.department}}',
        '{{employee.position}}',
        '{{employee.location}}',
        '{{employee.employment_type}}',
        '{{employee.start_date}}',
        '{{tenant.name}}',
        '{{today}}',
    ];

    public static function render(string $template, Employee $employee, Tenant $tenant): string
    {
        return strtr($template, [
            '{{employee.name}}' => $employee->fullName(),
            '{{employee.employee_number}}' => (string) $employee->employee_number,
            '{{employee.email}}' => (string) ($employee->work_email ?? ''),
            '{{employee.department}}' => (string) ($employee->department?->name ?? ''),
            '{{employee.position}}' => (string) ($employee->position?->name ?? ''),
            '{{employee.location}}' => (string) ($employee->location?->name ?? ''),
            '{{employee.employment_type}}' => str_replace('_', ' ', $employee->employment_type->value),
            '{{employee.start_date}}' => (string) ($employee->start_date?->toDateString() ?? ''),
            '{{tenant.name}}' => $tenant->name,
            '{{today}}' => now()->toDateString(),
        ]);
    }
}
