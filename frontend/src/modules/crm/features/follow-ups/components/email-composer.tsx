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
  return (
    <div className={cn("flex flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card shadow-card", className)}>
      <ComposerHeader item={item} onBack={onBack} />

      <div className="px-4 pt-4 md:px-6">
        <div className="rounded-xl border border-teal-400/30 bg-[var(--color-ai-banner-bg)] px-4 py-3">
          <div className="flex items-center gap-1.5">
            <span className="text-[11px] font-bold uppercase tracking-[0.08em] text-teal-900">
              AI Suggested Next Action
            </span>
            <Zap className="h-3.5 w-3.5 shrink-0 text-teal-500" />
          </div>
          <p className="mt-1.5 text-sm text-teal-900">{draft.aiSuggestion}</p>
        </div>
      </div>

      <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 py-4 md:px-6">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <FormField label="EMAIL PURPOSE">
            <Select
              value={draft.purpose}
              onValueChange={(v) => onUpdateDraft({ purpose: v ?? "" })}
            >
              <SelectTrigger className="!h-10 w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="Proposal Check-in">Proposal Check-in</SelectItem>
                <SelectItem value="Follow-up">Follow-up</SelectItem>
                <SelectItem value="Introduction">Introduction</SelectItem>
              </SelectContent>
            </Select>
          </FormField>
          <FormField label="TONE">
            <Select
              value={draft.tone}
              onValueChange={(v) => onUpdateDraft({ tone: v ?? "" })}
            >
              <SelectTrigger className="!h-10 w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="Warm & professional">Warm & professional</SelectItem>
                <SelectItem value="Formal">Formal</SelectItem>
                <SelectItem value="Casual">Casual</SelectItem>
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
