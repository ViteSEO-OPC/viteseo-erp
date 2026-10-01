"use client";

import { useCallback, useMemo, useRef, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import type {
  Message,
  RichTextSegment,
  SuggestedPrompt,
} from "@/modules/crm/features/copilot/types/copilot-types";
import type { Intent, IntentConfig, CopilotContext } from "@/modules/crm/features/copilot/engine/types";
import { matchIntentWithEntity, findIntent, extractEntityPhrase } from "@/modules/crm/features/copilot/engine/matcher";
import { buildResponse } from "@/modules/crm/features/copilot/engine/response-engine";
import { runAction } from "@/modules/crm/features/copilot/engine/action-engine";
import { isAiEligible, isLeadAiEligible, leadIdForAi } from "@/modules/crm/features/copilot/engine/ai-fallback";
import { useCopilotContext } from "@/modules/crm/features/copilot/hooks/use-copilot-context";
import { useCopilotData } from "@/modules/crm/features/copilot/hooks/use-copilot-data";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useCopilotLeadLookup } from "@/modules/crm/features/copilot/hooks/use-copilot-lead";
import { useLeadActivityLookup } from "@/modules/crm/features/copilot/hooks/use-copilot-lead-activity";
import { useOverdueFollowUpLookup } from "@/modules/crm/features/copilot/hooks/use-copilot-followups";
import { useCopilotActivityFeed } from "@/modules/crm/features/copilot/hooks/use-copilot-activity-feed";
import { useCopilotAi, useCopilotAiAvailable } from "@/modules/crm/features/copilot/hooks/use-copilot-ai";
import intentData from "@/modules/crm/features/copilot/data/intents.json";
import quickActionData from "@/modules/crm/features/copilot/data/quick-actions.json";

// JSON is validated by the engine types at the module boundary (single cast here).
const INTENT_CONFIG = intentData as unknown as IntentConfig;

// Data-aware quick actions: chips are grouped by module in quick-actions.json and each chip may
// declare `requires` — condition keys evaluated against the current data + permissions. A chip
// shows only when all its conditions hold; chips with none are always-relevant navigation.
// Everything (labels, intents, conditions) lives in JSON — only the evaluation is code.
type QuickAction = { id: string; label: string; intentId: string; requires?: string[] };
const QUICK_ACTIONS = quickActionData as {
  default: string;
  modules: Record<string, QuickAction[]>;
};
const MAX_QUICK_ACTIONS = 4;

// Flat id → action lookup so runQuickAction can resolve a clicked chip from any module.
const QUICK_ACTION_BY_ID = new Map<string, QuickAction>(
  Object.values(QUICK_ACTIONS.modules)
    .flat()
    .map((action) => [action.id, action]),
);

// Map the current pathname to a quick-action module. More specific routes win (a lead's detail
// page before the leads list); unlisted pages fall back to the default set.
function quickActionModule(pathname: string): string {
  if (pathname.startsWith("/zerem/leads/")) return "lead-details";
  if (pathname === "/zerem/leads") return "leads";
  if (pathname.startsWith("/zerem/follow-ups")) return "outreach";
  if (pathname.startsWith("/zerem/my-performance")) return "performance";
  if (pathname.startsWith("/zerem/dashboard")) return "dashboard";
  return QUICK_ACTIONS.default;
}

// Capability flags that chip `requires` conditions are evaluated against — derived from the
// copilot data source (context) and the user's real permissions/role (no fabrication, no backend
// call). When the context holds no data, data conditions are false and those chips step aside for
// always-relevant navigation.
type Capabilities = Record<string, boolean>;

function computeCapabilities(
  ctx: CopilotContext,
  can: (action: string) => boolean,
  isManager: boolean,
): Capabilities {
  return {
    hasLeads: ctx.topLeads.length > 0,
    hasHighPriorityLeads:
      ctx.metrics.hotLeads > 0 || ctx.topLeads.some((lead) => lead.priority.toLowerCase() === "high"),
    hasNewLeads: ctx.metrics.newLeads > 0,
    hasFollowUps: ctx.metrics.followUps > 0 || ctx.followUpsList.length > 0,
    canEmail: can("crm.follow-ups.create"),
    isManager,
  };
}

// Route → module → filter by capabilities → cap. An unknown condition key resolves to false
// (fail-safe hide), so a mistyped condition hides its chip rather than showing it unconditionally.
function selectQuickActions(pathname: string, caps: Capabilities): QuickAction[] {
  const pool =
    QUICK_ACTIONS.modules[quickActionModule(pathname)] ??
    QUICK_ACTIONS.modules[QUICK_ACTIONS.default] ??
    [];
  return pool
    .filter((action) => (action.requires ?? []).every((key) => caps[key] === true))
    .slice(0, MAX_QUICK_ACTIONS);
}

/**
 * useCopilot — the single orchestration point for the rule-based assistant. Owns the conversation
 * state and runs each turn through the engine pipeline: match intent → build response → run
 * action. Business logic stays here/in the engine; the components stay purely presentational.
 *
 * Backwards-compatible with the previous static widget: the conversation is seeded with the exact
 * demo greeting (via useCopilotData), and the suggested prompts render the same labels — only now
 * they are wired to real intents.
 */
export function useCopilot() {
  const router = useRouter();
  const pathname = usePathname();
  const seed = useCopilotData();
  const ctx = useCopilotContext();
  const { can, hasMinRole } = usePermissions();
  const lookupLead = useCopilotLeadLookup();
  const lookupLastActivity = useLeadActivityLookup();
  const lookupOverdueFollowUps = useOverdueFollowUpLookup();
  const lookupRecentActivity = useCopilotActivityFeed();
  const askAi = useCopilotAi();
  const aiAvailable = useCopilotAiAvailable();

  const [messages, setMessages] = useState<Message[]>(seed.messages);
  const idCounter = useRef(seed.messages.length);

  // Data-aware chips: the current route + data + permissions decide which suggestions are relevant.
  // (label only for the UI contract; the full action — intent + conditions — stays in JSON.)
  const prompts: SuggestedPrompt[] = useMemo(() => {
    const caps = computeCapabilities(ctx, can, hasMinRole("crm_admin"));
    return selectQuickActions(pathname, caps).map(({ id, label }) => ({ id, label }));
  }, [pathname, ctx, can, hasMinRole]);

  const makeMessage = useCallback(
    (author: Message["author"], segments: RichTextSegment[]): Message => ({
      id: `msg-live-${(idCounter.current += 1)}`,
      author,
      segments,
      timestamp: new Date().toISOString(),
    }),
    [],
  );

  /**
   * Shared turn: append the user's bubble + the engine's reply, then run any action.
   *
   * Async ONLY for `lead_lookup`, which needs the named company resolved before a reply can be
   * built. Resolution happens here rather than inside the engine so `buildResponse` stays a
   * synchronous pure function of (config, context) — the property that makes every other handler
   * trivially testable. Every other intent takes the same path it always did, with no await.
   */
  const runTurn = useCallback(
    async (userText: string, intent: Intent, message: string, leadAi = false) => {
      let turnCtx = ctx;

      // Canonical follow-up figure, fetched only for the question that asks for it.
      if (intent.response.type === "followups_overdue") {
        turnCtx = { ...ctx, overdueFollowUps: await lookupOverdueFollowUps() };
      }

      // The caller's own recent activity, fetched only for the question that asks for it.
      if (intent.response.type === "recent_activity") {
        turnCtx = { ...ctx, recentActivity: await lookupRecentActivity() };
      }

      if (intent.response.type === "lead_lookup") {
        const term = extractEntityPhrase(message);
        const matches = term ? await lookupLead(term) : [];
        const only = matches.length === 1 ? matches[0] : null;

        // An open-ended question about the ONE lead the policy-scoped lookup resolved may be answered
        // by the AI, from the server's own re-authorised profile of that lead (PO decision D1). An
        // exact phrasing, every other lead intent, no match or several, a quick action, and any AI
        // failure all keep the rule-based reply below. Only free text sets `leadAi`.
        const leadId = only ? leadIdForAi(only.id) : null;
        if (leadAi && leadId !== null && isLeadAiEligible(intent, message, matches.length)) {
          const answer = await askAi(message, leadId);
          if (answer !== null) {
            const userMessage = makeMessage("user", [{ type: "text", text: userText }]);
            const aiMessage: Message = { ...makeMessage("ai", [{ type: "text", text: answer }]), aiAssisted: true };
            setMessages((prev) => [...prev, userMessage, aiMessage]);
            return;
          }
        }

        turnCtx = {
          ...ctx,
          leadResolution: {
            term,
            matches,
            // Planner facts come from data already in hand and are BD-gated at the source, so an
            // Admin/SA turn carries none regardless of which lead was named.
            plannerForLead: only ? (ctx.plannerByLead[only.id] ?? []) : [],
            plannerAvailable: ctx.schedule.available,
            // Fetched only when the question actually asks for it, and only for an unambiguous lead —
            // so no other turn pays for a request it will not read.
            lastActivity:
              only && intent.response.leadAsk === "last-contact"
                ? await lookupLastActivity(only.id)
                : null,
          },
        };
      }

      const result = buildResponse(intent.response, turnCtx);
      const userMessage = makeMessage("user", [{ type: "text", text: userText }]);
      const aiMessage = makeMessage("ai", result.segments);
      setMessages((prev) => [...prev, userMessage, aiMessage]);
      runAction(result.action, (href) => router.push(href));
    },
    [ctx, makeMessage, router, lookupLead, lookupLastActivity, lookupOverdueFollowUps, lookupRecentActivity, askAi],
  );

  /**
   * The OPTIONAL AI turn, reached only when the server reports AI available AND the turn is
   * AI-eligible (a genuine fallback or a knowledge-article match). A grounded answer is shown as
   * plain text with the "AI-assisted" footer and no action; anything else — declined, out of scope,
   * failed — takes the exact deterministic turn for the intent the matcher resolved: the same
   * article, or the same help.
   */
  const runAiFallback = useCallback(
    async (text: string, intent: Intent) => {
      const answer = await askAi(text);
      if (answer === null) {
        await runTurn(text, intent, text);
        return;
      }
      const userMessage = makeMessage("user", [{ type: "text", text }]);
      const aiMessage: Message = { ...makeMessage("ai", [{ type: "text", text: answer }]), aiAssisted: true };
      setMessages((prev) => [...prev, userMessage, aiMessage]);
    },
    [askAi, makeMessage, runTurn],
  );

  // Free-text entry from the input bar — resolve the intent via the matcher.
  const send = useCallback(
    (raw: string) => {
      const text = raw.trim();
      if (!text) return;
      const match = matchIntentWithEntity(text, INTENT_CONFIG);
      const { intent } = match;
      // AI only for a genuine fallback or a knowledge-article match; every real intent and direct
      // request for help takes the rule-based turn — except an open-ended question about one
      // resolved lead, which `runTurn` may offer to the AI (see isLeadAiEligible). Unless the server
      // says AI is available neither branch runs.
      if (aiAvailable && isAiEligible(match, INTENT_CONFIG)) {
        void runAiFallback(text, intent);
        return;
      }
      // With AI off `aiAvailable` is false, so a lead question takes exactly the rule-based turn.
      void runTurn(text, intent, text, aiAvailable);
    },
    [runTurn, runAiFallback, aiAvailable],
  );

  // Suggested-prompt chip — bind straight to its intent (deterministic, skips the matcher).
  const runQuickAction = useCallback(
    (prompt: SuggestedPrompt) => {
      const action = QUICK_ACTION_BY_ID.get(prompt.id);
      const intent = action ? findIntent(action.intentId, INTENT_CONFIG) : undefined;
      if (!intent) {
        send(prompt.label); // graceful fallback: treat the label as free text
        return;
      }
      void runTurn(prompt.label, intent, prompt.label);
    },
    [runTurn, send],
  );

  return {
    messages,
    prompts,
    status: seed.status,
    send,
    runQuickAction,
  };
}
