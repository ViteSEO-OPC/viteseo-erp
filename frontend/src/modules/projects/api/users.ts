import apiClient from "@/core/api/client";
import type { Paginated, User } from "@/core/types";

// Users (shared directory, for member/assignee pickers)
export async function getUsers(search?: string): Promise<User[]> {
  const { data } = await apiClient.get<Paginated<User>>("/projects/users", {
    params: { search, per_page: 100 },
  });
  return data.data;
}
