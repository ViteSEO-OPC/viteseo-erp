import apiClient from "@/core/api/client";
import type { ApiResource, Paginated } from "@/core/types";
import type { Comment } from "@/modules/projects/types/comment";

export async function getTaskComments(taskId: string): Promise<Paginated<Comment>> {
  const { data } = await apiClient.get<Paginated<Comment>>(
    `/projects/tasks/${taskId}/comments`,
  );
  return data;
}

export async function addTaskComment(taskId: string, content: string): Promise<Comment> {
  const { data } = await apiClient.post<ApiResource<Comment>>(
    `/projects/tasks/${taskId}/comments`,
    {
      content,
    },
  );
  return data.data;
}

/** Revise your own comment; the server preserves the original version. */
export async function updateComment(id: string, content: string): Promise<Comment> {
  const { data } = await apiClient.patch<ApiResource<Comment>>(
    `/projects/comments/${id}`,
    { content },
  );
  return data.data;
}
