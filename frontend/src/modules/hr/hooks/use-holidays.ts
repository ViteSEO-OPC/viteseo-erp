"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getHolidaySets,
  createHolidaySet,
  refreshHolidaySet,
  deleteHolidaySet,
  getHolidays,
  addHoliday,
  updateHoliday,
  deleteHoliday,
} from "@/modules/hr/api/holidays";
import type { HolidayInput } from "@/modules/hr/types/holiday";

const SETS_KEY = ["hr", "holiday-sets"] as const;
const holidaysKey = (id: number) => ["hr", "holidays", id] as const;

export function useHolidaySets() {
  return useQuery({ queryKey: SETS_KEY, queryFn: getHolidaySets });
}

export function useHolidays(setId: number | null) {
  return useQuery({
    queryKey: setId ? holidaysKey(setId) : ["hr", "holidays", "none"],
    queryFn: () => getHolidays(setId as number),
    enabled: setId != null,
  });
}

export function useCreateHolidaySet() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createHolidaySet,
    onSuccess: () => qc.invalidateQueries({ queryKey: SETS_KEY }),
  });
}

export function useRefreshHolidaySet() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => refreshHolidaySet(id),
    onSuccess: (_d, id) => {
      qc.invalidateQueries({ queryKey: SETS_KEY });
      qc.invalidateQueries({ queryKey: holidaysKey(id) });
    },
  });
}

export function useDeleteHolidaySet() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteHolidaySet(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: SETS_KEY }),
  });
}

export function useAddHoliday(setId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: HolidayInput) => addHoliday(setId, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: holidaysKey(setId) });
      qc.invalidateQueries({ queryKey: SETS_KEY });
    },
  });
}

export function useUpdateHoliday(setId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: HolidayInput }) => updateHoliday(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: holidaysKey(setId) }),
  });
}

export function useDeleteHoliday(setId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteHoliday(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: holidaysKey(setId) });
      qc.invalidateQueries({ queryKey: SETS_KEY });
    },
  });
}
