import apiClient from "@/core/api/client";
import type { ApiResource } from "@/core/types";
import type {
  ImportPlan,
  ImportPreviewTask,
  ImportResult,
} from "@/modules/projects/types/task-import";

/**
 * Download the Markdown brief for a project and hand it to the browser.
 *
 * Fetched through the api client rather than a plain link because the endpoint
 * is behind Sanctum and a bare `<a href>` carries no Authorization header.
 */
export async function downloadImportBrief(
  projectId: string,
  fallbackName = "sprintly-import-brief.md",
): Promise<void> {
  const response = await apiClient.get(
    `/projects/projects/${projectId}/tasks/import/template`,
    { responseType: "blob" },
  );

  const disposition = String(
    response.headers["content-disposition"] ?? "",
  );
  const match = disposition.match(/filename="?([^"]+)"?/);
  const filename = match?.[1] ?? fallbackName;

  const url = URL.createObjectURL(response.data as Blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  // Revoking immediately can cancel the download in some browsers; one tick is
  // enough for the click to have been handled.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

/** Dry run: parse and validate the uploaded plan. Creates nothing. */
export async function previewTaskImport(
  projectId: string,
  file: File,
  options?: {
    onUploadProgress?: (percent: number) => void;
    signal?: AbortSignal;
  },
): Promise<ImportPlan> {
  const form = new FormData();
  form.append("file", file);

  const { data } = await apiClient.post<ApiResource<ImportPlan>>(
    `/projects/projects/${projectId}/tasks/import/preview`,
    form,
    {
      signal: options?.signal,
      onUploadProgress: (event) => {
        if (options?.onUploadProgress && event.total) {
          options.onUploadProgress(
            Math.round((event.loaded / event.total) * 100),
          );
        }
      },
    },
  );

  return data.data;
}

/**
 * Create the plan the PM approved.
 *
 * Sends the corrected rows, not the original file — the preview is editable, so
 * the file on disk is no longer the source of truth by this point. The server
 * re-validates everything regardless.
 */
export async function commitTaskImport(
  projectId: string,
  tasks: ImportPreviewTask[],
): Promise<ImportResult> {
  const { data } = await apiClient.post<ApiResource<ImportResult>>(
    `/projects/projects/${projectId}/tasks/import`,
    { tasks: tasks.map(toCommitPayload) },
  );

  return data.data;
}

/**
 * Re-check the corrected rows without creating anything.
 *
 * The PM's inline fixes are judged by the server's own rules rather than a copy
 * of them living here — a client-side re-implementation would drift from
 * StoreTaskRequest the first time either side changed.
 */
export async function revalidateTaskImport(
  projectId: string,
  tasks: ImportPreviewTask[],
): Promise<Record<string, string[]>> {
  const { data } = await apiClient.post<ApiResource<ImportResult>>(
    `/projects/projects/${projectId}/tasks/import`,
    { tasks: tasks.map(toCommitPayload), dry_run: true },
  );

  return data.data.errors ?? {};
}

/** Strip the preview-only bookkeeping (raw values, errors) before sending. */
function toCommitPayload(task: ImportPreviewTask) {
  return {
    // Sent so the server reports errors against the same row identity the
    // preview is displaying, rather than relying on positional agreement.
    ref: task.ref,
    title: task.title,
    description: task.description,
    status_id: task.status_id,
    priority: task.priority,
    assignment_type: task.assignment_type,
    assignee_id: task.assignee_id,
    group_id: task.group_id,
    start_date: task.start_date,
    deadline: task.deadline,
    effort_score: task.effort_score,
    subtasks: task.subtasks.map((subtask) => ({
      ref: subtask.ref,
      name: subtask.name,
      description: subtask.description,
      status_id: subtask.status_id,
      priority: subtask.priority,
      assignee_id: subtask.assignee_id,
      effort_score: subtask.effort_score,
      deadline: subtask.deadline,
    })),
  };
}
