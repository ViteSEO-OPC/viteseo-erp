<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload of an assistant-generated plan, for a dry run.
 *
 * Open to both tiers, but they are not doing the same thing: a holder of
 * `projects.tasks.create` assigns work across the project, while a holder of only
 * `projects.tasks.propose` submits their own, capped far lower and pending review.
 * The permissions are checked explicitly rather than through `can('create', Task::class)`,
 * because TaskPolicy::create collapses the two into one answer and the whole point
 * here is to tell them apart — TaskImportService reads the same permission to
 * decide which rules a request runs under.
 */
class PreviewTaskImportRequest extends FormRequest
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
            // Plain-text JSON. `.txt` is allowed because saving a chat reply often
            // produces one, and 2 MB is far above a MAX_ITEMS-sized plan.
            'file' => ['required', 'file', 'mimes:json,txt', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Upload the .json file your assistant produced.',
            'file.max' => 'That file is larger than 2 MB — it is probably not a task plan.',
        ];
    }
}
