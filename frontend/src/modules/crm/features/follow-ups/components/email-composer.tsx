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

        <FormField label="SUBJECT">
          <Input
            className="h-10"
            value={draft.subject}
            onChange={(e) => onUpdateDraft({ subject: e.target.value })}
          />
        </FormField>

        <FormField label="EMAIL BODY">
          <Textarea
            className="min-h-[200px]"
            value={draft.body}
            onChange={(e) => onUpdateDraft({ body: e.target.value })}
          />
        </FormField>
      </div>

      <ComposerActionBar
        scheduleEnabled={scheduler.enabled}
        onScheduleToggle={onToggleSchedule}
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
