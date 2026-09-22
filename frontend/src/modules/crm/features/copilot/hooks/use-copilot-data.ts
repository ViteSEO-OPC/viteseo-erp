"use client";

import type { Message, SuggestedPrompt, CopilotStatus } from "@/modules/crm/features/copilot/types/copilot-types";
import { useAuth } from "@/modules/crm/providers/auth-provider";

// First name from the centralized auth user (single source of truth). Falls back to a neutral
// greeting only in the (guaranteed-authenticated) edge case where no user is loaded yet.
function firstNameOf(fullName: string | undefined): string {
  const first = fullName?.trim().split(/\s+/)[0];
  return first && first.length > 0 ? first : "there";
}

// Seed message shown when the Copilot opens. Personalized only by the current user's first name —
// it intentionally names NO leads and quotes NO counts, so nothing here is a fabricated CRM record.
// Specific recommendations are generated on demand from the frontend data source (useCopilotContext).
function buildSeedMessages(firstName: string): Message[] {
  return [
    {
      id: "msg-1",
      author: "ai",
      segments: [
        { type: "text", text: `Hi ${firstName} — I'm your ` },
        { type: "emphasis", text: "Vite Copilot" },
        { type: "text", text: ". Pull up priority leads, follow-ups, and email, or open " },
        { type: "link", label: "Leads", route: "/zerem/leads" },
        { type: "text", text: ", " },
        { type: "link", label: "Dashboard", route: "/zerem/dashboard" },
        { type: "text", text: ", or " },
        { type: "link", label: "My Performance", route: "/zerem/my-performance" },
        { type: "text", text: ". Use a quick action below to start." },
      ],
      timestamp: "2026-07-03T09:01:00",
    },
  ];
}

// Fallback suggested prompts. The live chips are sourced from quick-actions.json via useCopilot;
// these are kept in sync (label-only, no CRM records) for any consumer of useCopilotData.prompts.
const MOCK_PROMPTS: SuggestedPrompt[] = [
  { id: "prompt-1", label: "Who should I call today?" },
  { id: "prompt-2", label: "Draft a follow-up email" }, 
  { id: "prompt-3", label: "Summarize this week" },
  { id: "prompt-4", label: "Score my new leads" },
];

// Header status affordance. `leadsAnalyzed` is 0 because there is no frontend lead source wired in
// — the Copilot never claims a fabricated count.
const MOCK_STATUS: CopilotStatus = {
  active: true,
  leadsAnalyzed: 0,
};

export function useCopilotData() {
  const { user } = useAuth();
  return {
    messages: buildSeedMessages(firstNameOf(user?.name)),
    prompts: MOCK_PROMPTS,
    status: MOCK_STATUS,
  };
}
