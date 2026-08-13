"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getConcerns,
  getMyConcerns,
  createConcern,
  setConcernStatus,
  reopenConcern,
  cancelConcern,
  scheduleMeeting,
  scheduleEmployeeMeeting,
  getMyMeetings,
  respondMeeting,
  requestConcernReveal,
  acceptConcernReveal,
  declineConcernReveal,
} from "@/modules/hr/api/concerns";

const CONCERNS_KEY = ["hr", "concerns"] as const;
const MY_CONCERNS_KEY = ["hr", "me", "concerns"] as const;
const MY_MEETINGS_KEY = ["hr", "me", "meetings"] as const;

export function useConcerns() {
  return useQuery({ queryKey: CONCERNS_KEY, queryFn: getConcerns });
}

export function useMyConcerns() {
  return useQuery({ queryKey: MY_CONCERNS_KEY, queryFn: getMyConcerns });
}

export function useCreateConcern() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createConcern,
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_CONCERNS_KEY }),
  });
}

// The employee withdrawing their own pending concern. Reason required.
export function useCancelConcern() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => cancelConcern(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: MY_CONCERNS_KEY });
      qc.invalidateQueries({ queryKey: CONCERNS_KEY });
    },
  });
}

export function useSetConcernStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => setConcernStatus(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: CONCERNS_KEY }),
  });
}

export function useReopenConcern() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenConcern(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: CONCERNS_KEY });
      qc.invalidateQueries({ queryKey: MY_CONCERNS_KEY });
    },
  });
}

/**
 * Every reveal decision changes what the OTHER side's list says, so both are invalidated on
 * each of the three mutations below — HR's queue holds the ask, the employee's page holds the
 * answer, and an accepted one changes whether a name is emitted at all.
 */
function invalidateBothQueues(qc: ReturnType<typeof useQueryClient>) {
  qc.invalidateQueries({ queryKey: CONCERNS_KEY });
  qc.invalidateQueries({ queryKey: MY_CONCERNS_KEY });
  // The bell carries the employee's pending ask.
  qc.invalidateQueries({ queryKey: ["hr", "me", "notifications"] });
}

/** HR asks an anonymous reporter to identify themselves. */
export function useRequestConcernReveal() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ concernId, reason }: { concernId: number; reason: string }) =>
      requestConcernReveal(concernId, reason),
    onSuccess: () => invalidateBothQueues(qc),
  });
}

/** The reporter agrees — this is what makes their name visible to HR. */
export function useAcceptConcernReveal() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => acceptConcernReveal(id),
    onSuccess: () => invalidateBothQueues(qc),
  });
}

/** The reporter declines, with a reason HR sees in place of the name. */
export function useDeclineConcernReveal() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => declineConcernReveal(id, reason),
    onSuccess: () => invalidateBothQueues(qc),
  });
}

export function useScheduleMeeting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      concernId,
      input,
    }: {
      concernId: number;
      input: { scheduled_at: string; location: string | null; agenda: string | null };
    }) => scheduleMeeting(concernId, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: CONCERNS_KEY }),
  });
}

export function useScheduleEmployeeMeeting() {
  return useMutation({
    mutationFn: ({
      employeeId,
      input,
    }: {
      employeeId: number;
      input: { scheduled_at: string; location: string | null; agenda: string | null };
    }) => scheduleEmployeeMeeting(employeeId, input),
  });
}

export function useMyMeetings() {
  return useQuery({ queryKey: MY_MEETINGS_KEY, queryFn: getMyMeetings });
}

export function useRespondMeeting() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, response }: { id: number; response: "accepted" | "declined" }) =>
      respondMeeting(id, response),
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_MEETINGS_KEY }),
  });
}
