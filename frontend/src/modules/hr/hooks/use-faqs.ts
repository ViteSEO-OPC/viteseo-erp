"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getFaqs,
  createFaq,
  updateFaq,
  deleteFaq,
  setFaqStatus,
  pinFaq,
  viewFaq,
} from "@/modules/hr/api/faq";
import type { FaqInput } from "@/modules/hr/types/faq";

const key = (search?: string) => ["hr", "faqs", search ?? ""] as const;

export function useFaqs(search?: string) {
  return useQuery({ queryKey: key(search), queryFn: () => getFaqs(search) });
}

export function useCreateFaq() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: FaqInput) => createFaq(input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "faqs"] }),
  });
}

export function useUpdateFaq() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, input }: { id: number; input: FaqInput }) => updateFaq(id, input),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "faqs"] }),
  });
}

export function useDeleteFaq() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => deleteFaq(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "faqs"] }),
  });
}

export function useSetFaqStatus() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: "publish" | "archive" }) => setFaqStatus(id, action),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "faqs"] }),
  });
}

export function usePinFaq() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => pinFaq(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["hr", "faqs"] }),
  });
}

export function useViewFaq() {
  return useMutation({ mutationFn: (id: number) => viewFaq(id) });
}
