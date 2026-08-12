import apiClient from "@/core/api/client";
import type { ApiResource } from "@/core/types";
import type { SearchResults } from "@/modules/projects/types/search";

// Global search across projects and tasks (topbar jump-to menu). `q` must be
// at least SEARCH_MIN_LENGTH characters or the API rejects it with a 422.
// `signal` comes from TanStack Query so a superseded keystroke's request is
// aborted instead of racing the current one.
export async function searchProjects(
  q: string,
  limit = 5,
  signal?: AbortSignal,
): Promise<SearchResults> {
  const { data } = await apiClient.get<ApiResource<SearchResults>>(
    "/projects/search",
    { params: { q, limit }, signal },
  );
  return data.data;
}
