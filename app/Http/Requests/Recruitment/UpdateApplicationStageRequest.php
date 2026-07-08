<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\ApplicationStage;
use App\Models\RecruitmentApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateApplicationStageRequest extends FormRequest
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
            'stage' => ['required', new Enum(ApplicationStage::class)],
        ];
    }

    /**
     * The requested stage must be a legal transition from the
     * application's *current* stage — mirrors UpdateLifecycleProcessRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('stage')) {
                return;
            }

            /** @var RecruitmentApplication $application */
            $application = $this->route('jobApplication');
            $requested = ApplicationStage::from($this->input('stage'));

            if (! $application->stage->canTransitionTo($requested)) {
                $validator->errors()->add('stage', "Cannot transition from {$application->stage->value} to {$requested->value}.");
            }
        });
    }
}
