"use client";

import { useQuery } from "@tanstack/react-query";
import { getUsers } from "@/modules/projects/api/users";
import { projectKeys } from "@/modules/projects/lib/query-keys";

export function useUsers(search?: string) {
  return useQuery({
    queryKey: [...projectKeys.users, search ?? ""],
    queryFn: () => getUsers(search),
  });
}
