"use client";

import { useQuery } from "@tanstack/react-query";

import { apiClient } from "@/core/api-client";

export interface SearchItem {
  title: string;
  subtitle: string;
  href: string;
}
export interface SearchGroup {
  label: string;
  items: SearchItem[];
}
export interface SearchResults {
  groups: SearchGroup[];
}

async function searchGlobal(q: string): Promise<SearchResults> {
  const res = await apiClient.get("/hr/search", { params: { q } });
  return res.data.data;
}

export function useSearch(q: string) {
  return useQuery({
    queryKey: ["hr", "search", q],
    queryFn: () => searchGlobal(q),
    enabled: q.trim().length >= 2,
  });
}
