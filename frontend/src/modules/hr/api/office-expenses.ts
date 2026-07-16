import { apiClient } from "@/core/api-client";
import type {
  OfficeExpense,
  OfficeExpenseInput,
} from "@/modules/hr/types/office-expense";

export async function getOfficeExpenses(): Promise<OfficeExpense[]> {
  const res = await apiClient.get("/hr/office-expenses");
  return res.data.data;
}

function toFormData(input: OfficeExpenseInput, receipt?: File | null): FormData {
  const fd = new FormData();
  fd.append("category", input.category);
  fd.append("amount", String(input.amount));
  fd.append("expense_date", input.expense_date);
  fd.append("description", input.description);
  if (input.vendor) fd.append("vendor", input.vendor);
  if (receipt) fd.append("receipt", receipt);
  return fd;
}

export async function createOfficeExpense(
  input: OfficeExpenseInput,
  receipt?: File | null,
): Promise<OfficeExpense> {
  const res = await apiClient.post("/hr/office-expenses", toFormData(input, receipt));
  return res.data.data;
}

export async function updateOfficeExpense(
  id: number,
  input: OfficeExpenseInput,
  receipt?: File | null,
): Promise<OfficeExpense> {
  const fd = toFormData(input, receipt);
  // Laravel needs the method spoofed for multipart PUT.
  fd.append("_method", "PUT");
  const res = await apiClient.post(`/hr/office-expenses/${id}`, fd);
  return res.data.data;
}

export async function approveOfficeExpense(id: number): Promise<OfficeExpense> {
  const res = await apiClient.post(`/hr/office-expenses/${id}/approve`);
  return res.data.data;
}

export async function rejectOfficeExpense(id: number, reason: string): Promise<OfficeExpense> {
  const res = await apiClient.post(`/hr/office-expenses/${id}/reject`, { reason });
  return res.data.data;
}

export async function deleteOfficeExpense(id: number): Promise<void> {
  await apiClient.delete(`/hr/office-expenses/${id}`);
}
