"use client";

import { useState } from "react";
import { Mail } from "lucide-react";
import { FollowUpInbox } from "@/modules/crm/features/follow-ups/components/follow-up-inbox";
import { EmailComposer } from "@/modules/crm/features/follow-ups/components/email-composer";
import { EmptyPanel } from "@/modules/crm/features/follow-ups/components/empty-panel";
import { DraftsList } from "@/modules/crm/features/follow-ups/components/drafts-list";
import { SchedulerPanel } from "@/modules/crm/features/follow-ups/components/scheduler-panel";
import { EmailDesignPreviewDialog } from "@/modules/crm/features/follow-ups/components/email-design-preview-dialog";
import { OutreachTabs, type OutreachTab } from "@/modules/crm/features/follow-ups/components/outreach-tabs";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { notify as toast } from "@/core/lib/notify";
import { businessLocalToUtcIso } from "@/modules/crm/lib/timezone";
import { useFollowUpsData } from "@/modules/crm/features/follow-ups/hooks/use-follow-ups-data";
import {
  useSendEmail,
  useDrafts,
  useSaveDraft,
  useUpdateDraft,
  useDiscardDraft,
  useSendDraft,
  type DraftEmail,
} from "@/modules/crm/features/follow-ups/hooks/use-outreach-mutations";

type MobileView = "inbox" | "composer";

type FollowUpsWorkspaceProps = {
  /** Lead to auto-focus, from the /follow-ups?lead=<id> deep-link (Lead Detail's Draft & send). */
  leadId?: string;
};

export function FollowUpsWorkspace({ leadId }: FollowUpsWorkspaceProps) {
  const {
    items,
    selectedId,
    selectedItem,
    draft,
    scheduler,
    activeDraftId,
    setActiveDraftId,
    loadDraft,
    selectItem,
    markDone,
    markingDone,
    updateDraft,
    toggleSchedule,
    setScheduleDatetime,
    purposeOptions,
    toneOptions,
    selectPurpose,
    selectTone,
    regenerate,
    canRegenerate,
    isDraftEdited,
    sendableTemplateId,
    templateFormat,
    designNotice,
  } = useFollowUpsData(leadId);

  const sendEmail = useSendEmail();
  const drafts = useDrafts();
  const saveDraft = useSaveDraft();
  const updateDraftMutation = useUpdateDraft();
  const discardDraft = useDiscardDraft();
  const sendDraft = useSendDraft();

  const [mobileView, setMobileView] = useState<MobileView>("inbox");
  const [schedulerOpen, setSchedulerOpen] = useState(false);
  const [activeTab, setActiveTab] = useState<OutreachTab>("followups");
  const [regenerateOpen, setRegenerateOpen] = useState(false);
  const [designPreviewOpen, setDesignPreviewOpen] = useState(false);

  // Send: an already-saved draft transitions in place (same row); otherwise a fresh send.
  // The inbox row is a PlannerEvent, so the email targets its linked lead (`leadId`), not the
  // event id. A follow-up with no linked lead has no send target.
  //
  // Auto-complete (Planner Email Tasks only): when the selected row is a Planner `email` task and
  // the send SUCCEEDS (returned Email.status === 'sent'), complete its Planner event through the
  // EXISTING completion flow (markDone → PATCH /planner-events → observer logs planner-event.completed
  // → KPI reacts). This closes the flow Planner Email Task → Composer → Send → Completed. Normal
  // follow-ups/calls/meetings/tasks are unaffected (they still complete only via manual Mark Done),
  // and a failed send (onError) never completes.
  const handleSend = () => {
    if (activeDraftId) {
      sendDraft.mutate(activeDraftId, { onSuccess: () => setActiveDraftId(null) });
      return;
    }
    const item = selectedItem;
    if (!item?.leadId) return;

    // RC3 Send-Later. The toggle now actually reaches the backend: with scheduling ON we convert the
    // business-timezone wall-clock the BD picked into the UTC instant the API stores, and send it as
    // `scheduled_at`. With scheduling OFF nothing is added and the immediate-send path is byte-for-byte
    // what it was. Same endpoint and same pipeline either way — no second send path.
    let scheduledAt: string | undefined;
    if (scheduler.enabled) {
      const local = scheduler.datetime.trim();
      const utc = local ? businessLocalToUtcIso(local) : "";
      // Re-validated here because the input's `min` is only a UI affordance (and the value can be
      // typed). The backend enforces `date|after:now` too; refusing early gives a usable message
      // instead of a 422.
      if (!utc || Number.isNaN(new Date(utc).getTime()) || new Date(utc).getTime() <= Date.now()) {
        toast.error("Pick a future date and time to schedule this email.");
        return;
      }
      scheduledAt = utc;
    }

    sendEmail.mutate(
      {
        leadId: item.leadId,
        subject: draft.subject,
        body: draft.body,
        to: item.email || undefined,
        scheduledAt,
        // Null once the BD has edited the template's text — see `sendableTemplateId`.
        emailTemplateId: sendableTemplateId ?? undefined,
      },
      {
        onSuccess: (email) => {
          // Unchanged: a Planner Email Task completes only on an ACTUAL send. A scheduled email
          // returns status=scheduled, so the task correctly stays open until it is delivered.
          if (!item.isVirtual && item.eventType === "email" && email.status === "sent") {
            markDone(item.id);
          }
        },
      },
    );
  };

  // Mark Done: complete the selected follow-up PlannerEvent (removes it from the inbox). A virtual
  // lead context (deep-linked lead with no follow-up) has no event to complete, so it is skipped —
  // the button is disabled for it below.
  const handleMarkDone = () => {
    if (!selectedItem || selectedItem.isVirtual) return;
    markDone(selectedItem.id);
    setMobileView("inbox");
  };

  // Regenerate: swap in a different existing EMAIL PURPOSE for this lead while holding the BD's Tone
  // fixed, through the same deterministic template path the dropdowns use. It overwrites the
  // composer, so an edited draft is confirmed first rather than silently discarded.
  const handleRegenerate = () => {
    if (isDraftEdited) {
      setRegenerateOpen(true);
      return;
    }
    regenerate();
    toast.success("Email regenerated from the template");
  };

  const confirmRegenerate = () => {
    regenerate();
    setRegenerateOpen(false);
    toast.success("Email regenerated from the template");
  };

  // Save Draft: update the loaded draft in place, or create a new one for the selected lead.
  // The draft keeps the template it was composed from by the same rule a send uses — `sendableTemplateId`,
  // null once the text is edited — so sending it later carries that template's design, or does not.
  const handleSaveDraft = () => {
    if (activeDraftId) {
      updateDraftMutation.mutate({
        id: activeDraftId,
        subject: draft.subject,
        body: draft.body,
        to: selectedItem?.email || undefined,
        emailTemplateId: sendableTemplateId,
      });
      return;
    }
    if (!selectedItem?.leadId) return;
    saveDraft.mutate(
      {
        leadId: selectedItem.leadId,
        subject: draft.subject,
        body: draft.body,
        to: selectedItem.email || undefined,
        emailTemplateId: sendableTemplateId,
      },
      { onSuccess: (created) => setActiveDraftId(created.id) },
    );
  };

  const handleOpenDraft = (d: DraftEmail) => {
    loadDraft(d);
    setActiveTab("followups");
    setMobileView("composer");
  };

  const handleSelectItem = (id: string) => {
    selectItem(id);
    setMobileView("composer");
  };

  const handleBack = () => {
    setMobileView("inbox");
  };

  const handleToggleSchedule = () => {
    toggleSchedule();
    if (!scheduler.enabled) {
      setSchedulerOpen(true);
    }
  };

  // `h-full` WITHOUT `overflow-hidden`. Both halves carry weight:
  //
  // The height must stay DEFINITE. `flex-1` on the row below resolves against it, and that is what
  // bounds the inbox card so its list (`flex-1 overflow-y-auto`) can scroll inside a fixed frame.
  // Under `min-h-full` the height became content-driven instead: the row then contributes its
  // MAX-CONTENT height, and `min-h-[24rem]` is only a floor, never a cap — so 15 follow-ups grew
  // the row to 1238px, the list had nothing to scroll against, and the composer's action bar sat
  // 489px below the fold at 1440x900.
  //
  // Dropping `overflow-hidden` is what keeps short viewports usable. `h-full` is a height, not a
  // maximum, so when the row's 24rem floor needs more than the viewport leaves, the workspace
  // overflows <main> — which (already `flex-1 overflow-y-auto`) scrolls to reach it. With the old
  // `overflow-hidden` the panels were capped at `viewportHeight - 267` (topbar + <main>'s
  // pt-8/pb-24 + the tabs + this gap) with nothing able to scroll, so a short window clamped both
  // cards to a sliver — 53px at 320px tall — and the clipped content was simply unreachable.
  return (
    <div className="flex h-full flex-col gap-4">
      <OutreachTabs
        active={activeTab}
        onChange={setActiveTab}
        followUpsCount={items.length}
        draftsCount={drafts.data?.length ?? 0}
      />

      {/* `min-h-[24rem]` below is the floor that stops the panels collapsing, replacing the
          `min-h-0` that let them shrink to nothing. The composer's non-scrolling chrome — header,
          AI-suggestion banner, action bar — measures 283px, so below roughly that the card clips
          its own furniture however its inner scroller behaves; 24rem clears it with usable room
          for the body left over. Above the floor the row still resolves to the same `flex-1`
          remainder as before, so viewports that were already fine are unchanged. */}
      {activeTab === "followups" ? (
        <div className="flex min-h-[24rem] flex-1 gap-4 overflow-hidden lg:gap-6">
          <FollowUpInbox
            items={items}
            selectedId={selectedId}
            onSelectItem={handleSelectItem}
            className={mobileView === "composer" ? "hidden md:flex" : "flex"}
          />

          {selectedItem ? (
            <EmailComposer
              item={selectedItem}
              draft={draft}
              scheduler={scheduler}
              onUpdateDraft={updateDraft}
              purposeOptions={purposeOptions}
              toneOptions={toneOptions}
              onSelectPurpose={selectPurpose}
              onSelectTone={selectTone}
              templateFormat={templateFormat}
              designNotice={designNotice}
              onPreviewDesign={
                selectedItem.leadId && sendableTemplateId !== null && templateFormat?.isDesigned
                  ? () => setDesignPreviewOpen(true)
                  : undefined
              }
              onRegenerate={canRegenerate ? handleRegenerate : undefined}
              onToggleSchedule={handleToggleSchedule}
              // Withheld when the item has no lead: the draft endpoint is POST
              // /leads/{leadId}/emails/drafts, so there is nothing to save against. Previously the
              // handler returned silently and the click looked broken.
              onSaveDraft={selectedItem.leadId ? handleSaveDraft : undefined}
              savingDraft={saveDraft.isPending || updateDraftMutation.isPending}
              onMarkDone={selectedItem.isVirtual ? undefined : handleMarkDone}
              markingDone={markingDone}
              onSend={handleSend}
              sending={sendEmail.isPending || sendDraft.isPending}
              onBack={handleBack}
              className={mobileView === "inbox" ? "hidden md:flex" : "flex"}
            />
          ) : (
            <EmptyPanel
              icon={Mail}
              message="Select a lead to start an email."
              className={mobileView === "inbox" ? "hidden md:flex" : "flex"}
            />
          )}
        </div>
      ) : (
        <DraftsList
          drafts={drafts.data ?? []}
          isLoading={drafts.isLoading}
          onOpen={handleOpenDraft}
          onSend={(id) => sendDraft.mutate(id)}
          onDiscard={(id) => discardDraft.mutate(id)}
          pendingId={sendDraft.isPending ? sendDraft.variables : discardDraft.isPending ? discardDraft.variables : null}
        />
      )}

      <ConfirmDialog
        open={regenerateOpen}
        onOpenChange={setRegenerateOpen}
        title="Replace your edits?"
        description="Regenerating replaces the Subject and Body using a different Email Purpose for this lead, keeping your selected Tone. Your current changes will be lost."
        confirmLabel="Regenerate"
        onConfirm={confirmRegenerate}
      />

      <SchedulerPanel
        open={schedulerOpen}
        scheduler={scheduler}
        onToggle={toggleSchedule}
        onDatetimeChange={setScheduleDatetime}
        onClose={() => setSchedulerOpen(false)}
      />

      {/* Mounted only while the composer offers a preview: an unedited designed template on a lead. */}
      {selectedItem?.leadId && sendableTemplateId !== null && templateFormat?.isDesigned ? (
        <EmailDesignPreviewDialog
          open={designPreviewOpen}
          onOpenChange={setDesignPreviewOpen}
          leadId={selectedItem.leadId}
          templateId={sendableTemplateId}
          designName={templateFormat.designName}
          recipientName={selectedItem.name}
          subject={draft.subject}
        />
      ) : null}
    </div>
  );
}
