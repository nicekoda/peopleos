<?php

namespace App\Http\Requests\Lifecycle;

use App\Models\LifecycleProcess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Checkpoint 45 — bulk task reordering. task_ids must be the *complete*
 * set of the process's own task IDs (no more, no fewer, no foreign
 * IDs, no duplicates) — this replaces the whole order in one call
 * rather than moving a single task, so there is no ambiguity about
 * where an omitted task would land.
 */
class ReorderLifecycleTasksRequest extends FormRequest
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
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['required', 'string'],
        ];
    }

    /**
     * A completed/cancelled process is a closed book — same rule
     * StoreLifecycleTaskRequest already applies to adding a task.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var LifecycleProcess $process */
            $process = $this->route('lifecycleProcess');

            if ($process->status->isTerminal()) {
                $validator->errors()->add('task_ids', 'Cannot reorder tasks on a completed or cancelled process.');

                return;
            }

            $submitted = $this->input('task_ids');

            if (! is_array($submitted)) {
                return;
            }

            $actual = $process->tasks()->pluck('id')->all();

            $matches = count($submitted) === count($actual)
                && array_diff($submitted, $actual) === []
                && array_diff($actual, $submitted) === [];

            if (! $matches) {
                $validator->errors()->add('task_ids', "task_ids must contain exactly this process's current tasks, in the desired order.");
            }
        });
    }
}
