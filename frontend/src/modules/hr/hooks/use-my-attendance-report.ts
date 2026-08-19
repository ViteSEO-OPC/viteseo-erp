"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { getMyAttendanceReport } from "@/modules/hr/api/my-attendance";

/**
 * One month of the caller's own attendance. The month is part of the key, so moving between
 * months keeps each one cached instead of refetching a period that cannot change.
 */
export function useMyAttendanceReport(month?: string) {
  return useQuery({
    queryKey: ["hr", "me", "attendance-report", month ?? "current"],
    queryFn: () => getMyAttendanceReport(month),
    // A past month is settled; the current one only moves when the employee clocks in.
    staleTime: 60_000,
    // Keep the month on screen while the next one loads. Without it, changing the filter
    // unmounts every figure on the page and replaces it with a loading line — the reader
    // loses the month they were comparing against at the exact moment they wanted both.
    placeholderData: keepPreviousData,
  });
}
