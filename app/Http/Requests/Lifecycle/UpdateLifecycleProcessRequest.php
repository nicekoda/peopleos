<?php

namespace App\Http\Requests\Lifecycle;

use App\Enums\LifecycleProcessStatus;
use App\Models\LifecycleProcess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateLifecycleProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * employee_id/type are deliberately absent — which employee a process
     * belongs to and whether it's onboarding vs. offboarding are fixed at
     * creation, the same "immutable after create" rule already applied
     * to Department/Position/Location's slug. completed_at/created_by/
     * updated_by stay controller-only.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', new Enum(LifecycleProcessStatus::class)],
            'started_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Two checks the field rules above can't express: a terminal
     * (completed/cancelled) process rejects every update outright, and a
     * requested status change must be a legal transition from the
     * process's *current* status — not just any enum value.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LifecycleProcess $process */
            $process = $this->route('lifecycleProcess');

            if ($process->status->isTerminal()) {
                $validator->errors()->add('status', 'A completed or cancelled process can no longer be updated.');

                return;
            }

            if ($this->filled('status')) {
                $requested = LifecycleProcessStatus::from($this->input('status'));

                if (! $process->status->canTransitionTo($requested)) {
                    $validator->errors()->add('status', "Cannot transition from {$process->status->value} to {$requested->value}.");
                }
            }
        });
    }
}
