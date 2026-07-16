import { apiClient } from "@/core/api-client";
import type { Faq, FaqInput } from "@/modules/hr/types/faq";

export async function getFaqs(search?: string): Promise<Faq[]> {
  const res = await apiClient.get("/hr/faqs", {
    params: search ? { q: search } : undefined,
  });
  return res.data.data;
}

export async function createFaq(input: FaqInput): Promise<Faq> {
  const res = await apiClient.post("/hr/faqs", input);
  return res.data.data;
}

export async function updateFaq(id: number, input: FaqInput): Promise<Faq> {
  const res = await apiClient.put(`/hr/faqs/${id}`, input);
  return res.data.data;
}

export async function deleteFaq(id: number): Promise<void> {
  await apiClient.delete(`/hr/faqs/${id}`);
}

export async function setFaqStatus(id: number, action: "publish" | "archive"): Promise<Faq> {
  const res = await apiClient.post(`/hr/faqs/${id}/${action}`);
  return res.data.data;
}

export async function pinFaq(id: number): Promise<Faq> {
  const res = await apiClient.post(`/hr/faqs/${id}/pin`);
  return res.data.data;
}

export async function viewFaq(id: number): Promise<void> {
  await apiClient.post(`/hr/faqs/${id}/view`);
}
