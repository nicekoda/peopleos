<?php

namespace App\Http\Requests\Policy;

use App\Enums\PolicyStatus;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PublishPolicyRequest extends FormRequest
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
        $tenantId = app(Tenant::class)->id;
        /** @var Policy $policy */
        $policy = $this->route('policy');

        return [
            'policy_version_id' => [
                'required', 'string',
                Rule::exists('policy_versions', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('policy_id', $policy->id)
                    ->where('status', PolicyStatus::Draft->value)),
            ],
        ];
    }

    /**
     * Publishing rule: the version must have content or an attached
     * document — an empty draft can't be published.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $versionId = $this->input('policy_version_id');

            if (! $versionId) {
                return;
            }

            $version = PolicyVersion::query()->find($versionId);

            if ($version && ! $version->content && ! $version->employee_document_id) {
                $validator->errors()->add(
                    'policy_version_id',
                    'This version has no content or attached document and cannot be published.',
                );
            }
        });
    }
}
