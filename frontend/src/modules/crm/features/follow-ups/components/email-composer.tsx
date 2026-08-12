"use client";

import { Zap } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { Input } from "@/modules/crm/components/ui/input";
import { Textarea } from "@/modules/crm/components/ui/textarea";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { ComposerHeader } from "@/modules/crm/features/follow-ups/components/composer-header";
import { ComposerActionBar } from "@/modules/crm/features/follow-ups/components/composer-action-bar";
import type { FollowUpItem, EmailDraft, SchedulerState } from "@/modules/crm/features/follow-ups/types/follow-ups-types";

type EmailComposerProps = {
  item: FollowUpItem;
  draft: EmailDraft;
  scheduler: SchedulerState;
  onUpdateDraft: (updates: Partial<EmailDraft>) => void;
  // RC3 Phase 2 — Purpose/Tone options come from DB templates; selecting one populates Subject + Body.
  purposeOptions?: string[];
  toneOptions?: string[];
  onSelectPurpose?: (value: string) => void;
  onSelectTone?: (value: string) => void;
  /** Re-render Subject + Body from the current Purpose + Tone. Omitted when no template matches. */
  onRegenerate?: () => void;
  onToggleSchedule: () => void;
  onSaveDraft?: () => void;
  savingDraft?: boolean;
  onMarkDone?: () => void;
  markingDone?: boolean;
  onSend?: () => void;
  sending?: boolean;
  onBack?: () => void;
  className?: string;
};

export function EmailComposer({
  item,
  draft,
  scheduler,
  onUpdateDraft,
  purposeOptions,
  toneOptions,
  onSelectPurpose,
  onSelectTone,
  onRegenerate,
  onToggleSchedule,
  onSaveDraft,
  savingDraft,
  onMarkDone,
  markingDone,
  onSend,
  sending,
  onBack,
  className,
}: EmailComposerProps) {
  // Options come from DB templates; always include the current value so the trigger renders it while
  // templates load. Selecting a Purpose/Tone applies the matching template (falls back to a plain
  // field update if no selector was provided).
  const purposes = Array.from(new Set([...(purposeOptions ?? []), draft.purpose].filter(Boolean)));
  const tones = Array.from(new Set([...(toneOptions ?? []), draft.tone].filter(Boolean)));
  const handlePurpose = (v: string | null) => (onSelectPurpose ?? ((p: string) => onUpdateDraft({ purpose: p })))(v ?? "");
  const handleTone = (v: string | null) => (onSelectTone ?? ((t: string) => onUpdateDraft({ tone: t })))(v ?? "");

  return (
    <div className={cn("flex flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-card", className)}>
      <ComposerHeader item={item} onBack={onBack} />

      <div className="px-4 pt-4 md:px-6">
        <div className="rounded-xl border border-teal-400/30 bg-[var(--color-ai-banner-bg)] px-4 py-3">
          <div className="flex items-center gap-1.5">
            <span className="text-[11px] font-bold uppercase tracking-[0.08em] text-teal-900 dark:text-teal-400">
              AI Suggested Next Action
            </span>
            <Zap className="h-3.5 w-3.5 shrink-0 text-teal-500" />
          </div>
          <p className="mt-1.5 text-sm text-teal-900 dark:text-teal-400">{draft.aiSuggestion}</p>
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 md:px-6">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <FormField label="EMAIL PURPOSE">
            <Select value={draft.purpose} onValueChange={handlePurpose}>
              <SelectTrigger className="!h-10 w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {purposes.map((p) => (
                  <SelectItem key={p} value={p}>
                    {p}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormField>
          <FormField label="TONE">
            <Select value={draft.tone} onValueChange={handleTone}>
              <SelectTrigger className="!h-10 w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {tones.map((t) => (
                  <SelectItem key={t} value={t}>
                    {t}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FormField>
        </div>

        {/*
          A newly selected item starts BLANK (see the re-hydration effect in useFollowUpsData), so
          these placeholders carry the "what now?" — without them an empty composer reads as broken
          rather than as a clean start. Placeholder text is never submitted, unlike the seeded copy it
          replaced, which was a fully sendable email identical for every lead.
        */}
        <FormField label="SUBJECT">
          <Input
            className="h-10"
            value={draft.subject}
            placeholder="Write a subject, or pick a Purpose and Tone and click Generate"
            onChange={(e) => onUpdateDraft({ subject: e.target.value })}
          />
        </FormField>

        <FormField label="EMAIL BODY">
          <Textarea
            className="min-h-[200px]"
            value={draft.body}
            placeholder="Write your message here, or click Generate to draft it from the selected Purpose and Tone."
            onChange={(e) => onUpdateDraft({ body: e.target.value })}
          />
        </FormField>
      </div>

      {/*
        Keyed by the selected item so switching Outreach items REMOUNTS the bar. Without it React
        reconciles by position and reuses the instance, so `useCopyToClipboard`'s `copied` flag (and
        its pending 2s timer) survive the switch — copying item A then selecting B within 2s showed B
        as "Copied" while the clipboard actually held A's text. The bar holds no other state, so a
        remount costs nothing.
      */}
      <ComposerActionBar
        key={item.id}
        scheduleEnabled={scheduler.enabled}
        onScheduleToggle={onToggleSchedule}
        onRegenerate={onRegenerate}
        subject={draft.subject}
        body={draft.body}
        onSaveDraft={onSaveDraft}
        savingDraft={savingDraft}
        onMarkDone={onMarkDone}
        markingDone={markingDone}
        onSend={onSend}
        sending={sending}
      />
    </div>
  );
}
