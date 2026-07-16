<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Colors only — no custom CSS/HTML/JS field exists here at all (not
 * just rejected, genuinely absent from the rule set), per your
 * explicit approved scope. Strict 6-digit hex only — no named colors,
 * no rgb()/hsl(), nothing a browser would parse more leniently than
 * this regex allows.
 */
class UpdateTenantBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
