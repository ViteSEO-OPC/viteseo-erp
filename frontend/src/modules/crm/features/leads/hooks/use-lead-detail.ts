"use client";

import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type { LeadDetail, QuickAction } from "@/modules/crm/features/leads/types/lead-detail-types";
import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

type LeadApiDetail = {
  id: string;
  business: string;
  contact: string;
  email: string;
  phone: string;
  industry: string;
  websiteStatus: string;
  priority: "high" | "medium" | "low";
  emailOutreach: string;
  coldCalling: string;
  updateStatus: "interested" | "awaiting-reply" | "declined" | "call-back-req";
  score: number;
  amount: number | null;
  assignedToId: string | null;
  assignedTo: { id: string; name: string } | null;
  lastActivity: string;
};

// Quick Actions catalog (row actions). "email" redirects to the Outreach module with this lead;
// "follow-up" opens the Schedule Follow-up modal (existing Planner backend). Won/Lost + Lead Status
// are dedicated controls rendered directly by the card, not rows.
const QUICK_ACTIONS: QuickAction[] = [
  { id: "qa-1", key: "email", icon: "Mail", iconTone: "teal", title: "Draft & send email", subtitle: "Compose in Outreach" },
  { id: "qa-2", key: "follow-up", icon: "Calendar", iconTone: "amber", title: "Schedule follow-up", subtitle: "Set date & reminder" },
];

function mapToLeadDetail(api: LeadApiDetail): LeadDetail {
  return {
    id: api.id,
    business: api.business,
    contact: api.contact,
    email: api.email,
    phone: api.phone,
    industry: api.industry,
    score: api.score,
    amount: api.amount,
    priority: api.priority,
    status: api.updateStatus,
    assignedTo: api.assignedTo,
    lastActivity: api.lastActivity,
    chips: [
      { type: "priority", value: api.priority },
      { type: "status", value: api.updateStatus },
      { type: "website", value: api.websiteStatus },
    ],
  };
}

function toEditableLead(api: LeadApiDetail): Lead {
  return {
    id: api.id,
    business: api.business,
    contact: api.contact,
    email: api.email,
    phone: api.phone,
    industry: api.industry,
    websiteStatus: api.websiteStatus as Lead["websiteStatus"],
    priority: api.priority,
    emailOutreach: api.emailOutreach as Lead["emailOutreach"],
    coldCalling: api.coldCalling as Lead["coldCalling"],
    updateStatus: api.updateStatus,
    score: api.score,
    amount: api.amount,
    assignedToId: api.assignedToId,
    assignedTo: api.assignedTo,
    lastActivity: api.lastActivity,
  };
}

export function useLeadDetail(id: string) {
  const query = useQuery<{ data: LeadApiDetail }>({
    queryKey: queryKeys.leads.detail(id),
    queryFn: () => apiClient.get(`/leads/${id}`).then((r) => r.data),
    enabled: !!id,
  });

  const api = query.data?.data;

  return {
    lead: api ? mapToLeadDetail(api) : undefined,
    editableLead: api ? toEditableLead(api) : null,
    quickActions: QUICK_ACTIONS,
    isLoading: query.isLoading,
    isError: query.isError,
  };
}
