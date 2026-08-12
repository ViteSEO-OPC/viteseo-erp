<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Events\TasksImported;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\CommitTaskImportRequest;
use App\Modules\Projects\Requests\PreviewTaskImportRequest;
use App\Modules\Projects\Services\TaskImportService;
use App\Modules\Projects\Support\TaskImportBrief;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Bulk task import: download a brief, dry-run a plan, then create it.
 *
 * The PM hands the brief to an assistant, works the plan out in chat, and brings
 * back the JSON it produced. Thin by design — all of the work is in
 * TaskImportService and TaskImportBrief.
 */
class TaskImportController extends Controller
{
    public function __construct(
        private readonly TaskImportService $service,
    ) {}

    /**
     * The Markdown brief for this project — the thing the PM gives their assistant.
     */
    public function template(Request $request, Project $project): Response
    {
        $this->authorizeImport($request, $project);

        // Two briefs. A proposer's drops `assignee` and `group` entirely (they can
        // only post their own work), requires effort on every row, and carries no
        // roster data at all — so nothing about the team leaves the org on that path.
        $forMember = $this->service->isProposer($request->user());

        return response(TaskImportBrief::for($project, $forMember), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.TaskImportBrief::filename($project, $forMember).'"',
            // The browser reads the filename off this header via the blob download,
            // so it has to survive CORS.
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    /**
     * Dry run. Parses and validates the uploaded plan and writes nothing.
     */
    public function preview(PreviewTaskImportRequest $request, Project $project): JsonResponse
    {
        $this->authorizeImport($request, $project);

        $contents = file_get_contents($request->file('file')->getRealPath());

        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'That file could not be read.']);
        }

        return response()->json([
            'data' => $this->service->preview($project, $contents, $request->user()),
        ]);
    }

    /**
     * Create the plan the PM approved in the preview.
     *
     * With `dry_run` it re-validates the corrected rows and returns their errors
     * without writing — the preview's "Re-validate" runs through here so the
     * client never has to reimplement a rule.
     */
    public function store(CommitTaskImportRequest $request, Project $project): JsonResponse
    {
        $this->authorizeImport($request, $project);

        $result = $this->service->commit(
            $project,
            $request->tasks(),
            $request->user(),
            $request->isDryRun(),
        );

        if ($request->isDryRun()) {
            return response()->json(['data' => $result]);
        }

        // A proposer's import is pending review, so it is on nobody's board yet and
        // there is nothing to broadcast — the board learns about it when a PM
        // approves, exactly as TaskController::store reasons about a single
        // proposal. It is silent by design: no per-task review notification either,
        // so an import lands as one batch in the approvals queue rather than N
        // entries in every PM's bell.
        if ($result['created_tasks'] > 0 && ! $this->service->isProposer($request->user())) {
            SafeBroadcast::toOthers(new TasksImported(
                $project,
                $result['created_tasks'],
                $result['created_subtasks'],
            ));
        }

        return response()->json(['data' => $result], Response::HTTP_CREATED);
    }

    /**
     * Either tier may import into a project they can see — what differs is what
     * the import is allowed to produce, which TaskImportService decides from the
     * same permission.
     *
     * `view` still gates the project itself, so a member can only import into a
     * project they are actually on (ProjectPolicy::view enforces the roster).
     */
    private function authorizeImport(Request $request, Project $project): void
    {
        $user = $request->user();

        abort_unless(
            ($user?->can('projects.tasks.create') ?? false)
            || ($user?->can('projects.tasks.propose') ?? false),
            403,
        );

        $this->authorize('view', $project);
    }
}
