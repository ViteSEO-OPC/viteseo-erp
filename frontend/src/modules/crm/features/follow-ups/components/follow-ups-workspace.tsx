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

export function FollowUpsWorkspace() {
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
  } = useFollowUpsData();

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
  const handleSend = () => {
    if (activeDraftId) {
      sendDraft.mutate(activeDraftId, { onSuccess: () => setActiveDraftId(null) });
      return;
    }
    if (!selectedItem?.leadId) return;
    sendEmail.mutate({
      leadId: selectedItem.leadId,
      subject: draft.subject,
      body: draft.body,
      to: selectedItem.email || undefined,
    });
  };

  // Mark Done: complete the selected follow-up PlannerEvent (removes it from the inbox).
  const handleMarkDone = () => {
    if (!selectedItem) return;
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
              onToggleSchedule={handleToggleSchedule}
              onSaveDraft={handleSaveDraft}
              savingDraft={saveDraft.isPending || updateDraftMutation.isPending}
              onMarkDone={handleMarkDone}
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
