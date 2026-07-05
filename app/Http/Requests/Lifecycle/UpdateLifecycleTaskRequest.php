<?php

namespace App\Http\Requests\Lifecycle;

use App\Enums\LifecycleTaskStatus;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLifecycleTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * status is deliberately restricted below to pending/in_progress
     * only — completing or skipping a task goes through the dedicated
     * POST .../complete and .../skip endpoints, which set completed_at/
     * completed_by from trusted context. Allowing "completed"/"skipped"
     * through this generic update would let a caller set the status
     * without those fields ever being populated.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_to_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('is_platform_admin', false)
                    ->where('status', User::STATUS_ACTIVE)),
            ],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([LifecycleTaskStatus::Pending->value, LifecycleTaskStatus::InProgress->value])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LifecycleTask $task */
            $task = $this->route('lifecycleTask');

            if ($task->status->isTerminal()) {
                $validator->errors()->add('title', 'A completed or skipped task can no longer be updated.');

                return;
            }

            if ($task->process->status->isTerminal()) {
                $validator->errors()->add('title', 'Cannot update a task on a completed or cancelled process.');

                return;
            }

            if ($this->filled('status')) {
                $requested = LifecycleTaskStatus::from($this->input('status'));

                if (! $task->status->canTransitionTo($requested)) {
                    $validator->errors()->add('status', "Cannot transition from {$task->status->value} to {$requested->value}.");
                }
            }
        });
    }
}
