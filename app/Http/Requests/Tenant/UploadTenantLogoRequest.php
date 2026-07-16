<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * PNG/JPG/JPEG only — SVG deliberately excluded (SVG can carry
 * embedded scripts/external references, never accepted here regardless
 * of MIME spoofing since File::types() validates the real content, not
 * just the extension). Max dimensions enforced via Laravel's built-in
 * `dimensions` rule (uses PHP's GD extension, already present in this
 * environment — verified directly, not assumed) — no new dependency
 * needed. No external logo URL field exists at all; a logo is always
 * an uploaded file.
 */
class UploadTenantLogoRequest extends FormRequest
{
    public const MAX_FILE_SIZE_KB = 2 * 1024;

    public const MAX_DIMENSION_PX = 2000;

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
            'logo' => [
                'required',
                File::types(['png', 'jpg', 'jpeg'])
                    ->max(self::MAX_FILE_SIZE_KB),
                'dimensions:max_width='.self::MAX_DIMENSION_PX.',max_height='.self::MAX_DIMENSION_PX,
            ],
        ];
    }
}
