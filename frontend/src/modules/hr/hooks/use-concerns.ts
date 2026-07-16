"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getConcerns,
  getMyConcerns,
  createConcern,
  setConcernStatus,
  scheduleMeeting,
  scheduleEmployeeMeeting,
  getMyMeetings,
  respondMeeting,
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

export function useSetConcernStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => setConcernStatus(id, status),
    onSuccess: () => qc.invalidateQueries({ queryKey: CONCERNS_KEY }),
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
