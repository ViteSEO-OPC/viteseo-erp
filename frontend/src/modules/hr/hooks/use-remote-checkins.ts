"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  submitRemoteCheckin,
  getMyRemoteCheckin,
  getRemoteCheckins,
  approveRemoteCheckin,
  rejectRemoteCheckin,
} from "@/modules/hr/api/remote-checkins";
import type { LocationFix } from "@/modules/hr/types/attendance";

const MY_KEY = ["hr", "me", "remote-checkin"] as const;
const QUEUE_KEY = ["hr", "remote-checkins"] as const;
// The employee's clock panel — a decision here changes whether they're clocked in.
const MY_ATTENDANCE_KEY = ["hr", "me", "attendance"] as const;

/** The caller's own newest submission — drives the "awaiting approval" state. */
export function useMyRemoteCheckin(enabled = true) {
  return useQuery({ queryKey: MY_KEY, queryFn: getMyRemoteCheckin, enabled });
}

/** HR's queue. Polls, because a submission arrives without HR doing anything. */
export function useRemoteCheckins(status = "pending") {
  return useQuery({
    queryKey: [...QUEUE_KEY, status],
    queryFn: () => getRemoteCheckins(status),
    refetchInterval: 60_000,
  });
}

export function useSubmitRemoteCheckin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ photo, fix }: { photo: File; fix?: LocationFix | null }) =>
      submitRemoteCheckin(photo, fix),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: MY_KEY });
      qc.invalidateQueries({ queryKey: QUEUE_KEY });
    },
  });
}

export function useApproveRemoteCheckin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveRemoteCheckin(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: QUEUE_KEY });
      qc.invalidateQueries({ queryKey: MY_KEY });
      // Approving writes a real attendance row, so the roster and the tiles are now stale.
      qc.invalidateQueries({ queryKey: ["hr", "attendance"] });
      qc.invalidateQueries({ queryKey: MY_ATTENDANCE_KEY });
    },
  });
}

export function useRejectRemoteCheckin() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, note }: { id: number; note: string }) => rejectRemoteCheckin(id, note),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: QUEUE_KEY });
      qc.invalidateQueries({ queryKey: MY_KEY });
    },
  });
}
