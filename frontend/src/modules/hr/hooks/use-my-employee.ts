"use client";

import { useQuery } from "@tanstack/react-query";

import { getMyEmployee } from "@/modules/hr/api/employees";

export function useMyEmployee() {
  return useQuery({
    queryKey: ["hr", "me", "employee"],
    queryFn: getMyEmployee,
  });
}
