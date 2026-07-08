<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\ApplicationStage;
use App\Models\RecruitmentApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A milestone flag, not a stage — no automatic employee creation happens
 * here (Checkpoint 39 explicitly does not build conversion). Deliberately
 * its own permission (job_applications.mark_ready_for_conversion) rather
 * than folded into update_stage.
 */
class MarkApplicationReadyForConversionRequest extends FormRequest
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
            'ready_for_conversion' => ['required', 'boolean'],
        ];
    }

    /**
     * Rejected/withdrawn applications can never be marked ready — that
     * would contradict the outcome already recorded.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('ready_for_conversion')) {
                return;
            }

            /** @var RecruitmentApplication $application */
            $application = $this->route('jobApplication');

            if (in_array($application->stage, [ApplicationStage::Rejected, ApplicationStage::Withdrawn], true)) {
                $validator->errors()->add('ready_for_conversion', "Cannot mark a {$application->stage->value} application ready for conversion.");
            }
        });
    }
}
