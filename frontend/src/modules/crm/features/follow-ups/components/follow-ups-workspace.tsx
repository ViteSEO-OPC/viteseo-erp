"use client";

import { useState } from "react";
import { Mail } from "lucide-react";
import { FollowUpInbox } from "@/modules/crm/features/follow-ups/components/follow-up-inbox";
import { EmailComposer } from "@/modules/crm/features/follow-ups/components/email-composer";
import { EmptyPanel } from "@/modules/crm/features/follow-ups/components/empty-panel";
import { DraftsList } from "@/modules/crm/features/follow-ups/components/drafts-list";
import { SchedulerPanel } from "@/modules/crm/features/follow-ups/components/scheduler-panel";
import { OutreachTabs, type OutreachTab } from "@/modules/crm/features/follow-ups/components/outreach-tabs";
import { CopilotWidget } from "@/modules/crm/features/copilot/components/copilot-widget";
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
    sendEmail.mutate(
      { leadId: item.leadId, subject: draft.subject, body: draft.body, to: item.email || undefined },
      {
        onSuccess: (email) => {
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

  // Save Draft: update the loaded draft in place, or create a new one for the selected lead.
  const handleSaveDraft = () => {
    if (activeDraftId) {
      updateDraftMutation.mutate({
        id: activeDraftId,
        subject: draft.subject,
        body: draft.body,
        to: selectedItem?.email || undefined,
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

  return (
    <div className="flex h-full min-h-0 flex-1 flex-col gap-4 overflow-hidden">
      <OutreachTabs
        active={activeTab}
        onChange={setActiveTab}
        followUpsCount={items.length}
        draftsCount={drafts.data?.length ?? 0}
      />

      {activeTab === "followups" ? (
        <div className="flex min-h-0 flex-1 gap-4 overflow-hidden lg:gap-6">
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
              onToggleSchedule={handleToggleSchedule}
              onSaveDraft={handleSaveDraft}
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

      <SchedulerPanel
        open={schedulerOpen}
        scheduler={scheduler}
        onToggle={toggleSchedule}
        onDatetimeChange={setScheduleDatetime}
        onClose={() => setSchedulerOpen(false)}
      />

      <CopilotWidget />
    </div>
  );
}
