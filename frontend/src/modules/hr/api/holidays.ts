import { apiClient } from "@/core/api-client";
import type {
  Holiday,
  HolidaySet,
  HolidayInput,
} from "@/modules/hr/types/holiday";

export async function getHolidaySets(): Promise<HolidaySet[]> {
  const res = await apiClient.get("/hr/holiday-sets");
  return res.data.data;
}

export async function createHolidaySet(input: {
  country_code: string;
  year: number;
}): Promise<HolidaySet> {
  const res = await apiClient.post("/hr/holiday-sets", input);
  return res.data.data;
}

export async function refreshHolidaySet(id: number): Promise<HolidaySet> {
  const res = await apiClient.post(`/hr/holiday-sets/${id}/refresh`);
  return res.data.data;
}

export async function deleteHolidaySet(id: number): Promise<void> {
  await apiClient.delete(`/hr/holiday-sets/${id}`);
}

export async function getHolidays(setId: number): Promise<Holiday[]> {
  const res = await apiClient.get(`/hr/holiday-sets/${setId}/holidays`);
  return res.data.data;
}

export async function addHoliday(setId: number, input: HolidayInput): Promise<Holiday> {
  const res = await apiClient.post(`/hr/holiday-sets/${setId}/holidays`, input);
  return res.data.data;
}

export async function updateHoliday(id: number, input: HolidayInput): Promise<Holiday> {
  const res = await apiClient.put(`/hr/holidays/${id}`, input);
  return res.data.data;
}

export async function deleteHoliday(id: number): Promise<void> {
  await apiClient.delete(`/hr/holidays/${id}`);
}
