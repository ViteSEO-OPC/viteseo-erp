<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Services\TaskImportService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The corrected plan coming back from the preview screen.
 *
 * Only the envelope is checked here — shape, size and types. Every field-level
 * rule lives in TaskImportService so preview and commit judge a row by exactly
 * the same standard; duplicating them here would let the two drift.
 */
class CommitTaskImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Either tier may import; TaskImportService decides what the import is
        // allowed to produce from this same permission. A proposer's rows are
        // forced to their own name and land pending review.
        return ($this->user()?->can('projects.tasks.create') ?? false)
            || ($this->user()?->can('projects.tasks.propose') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tasks' => ['required', 'array', 'min:1', 'max:'.TaskImportService::MAX_TASKS],
            'tasks.*' => ['required', 'array'],
            'tasks.*.subtasks' => ['sometimes', 'array'],
            'tasks.*.subtasks.*' => ['required', 'array'],
            // Re-validate the corrected plan without creating anything. Lets the
            // preview screen re-check the PM's inline fixes against the real rules
            // instead of a client-side copy of them.
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }

    public function isDryRun(): bool
    {
        return $this->boolean('dry_run');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tasks.max' => 'An import can carry at most '.TaskImportService::MAX_TASKS.' tasks.',
        ];
    }

    /**
     * The plan, as sent.
     *
     * Deliberately NOT `validated('tasks')`: `validated()` returns only the paths
     * that carry rules, so declaring `tasks.*.subtasks` narrows each task down to
     * its `subtasks` key and silently drops `title`, `priority` and the rest. The
     * rules above exist to guarantee the envelope (present, a list, within the
     * cap, every entry an object); TaskImportService validates the fields.
     *
     * @return list<array<string, mixed>>
     */
    public function tasks(): array
    {
        /** @var list<array<string, mixed>> $tasks */
        $tasks = $this->input('tasks', []);

        return $tasks;
    }
}
