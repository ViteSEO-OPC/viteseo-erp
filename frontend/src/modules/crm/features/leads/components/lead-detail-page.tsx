"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useLeadDetail } from "@/modules/crm/features/leads/hooks/use-lead-detail";
import { useLeadChannel } from "@/modules/crm/features/leads/hooks/use-lead-realtime";
import { useLeadActivities } from "@/modules/crm/features/leads/hooks/use-lead-activities";
import { useDeleteLead, useUpdateLead } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import { LeadDetailHeader } from "@/modules/crm/features/leads/components/lead-detail-header";
import { useExportLeadPdf } from "@/modules/crm/features/leads/hooks/use-export-lead-pdf";
import { LeadSummaryCard } from "@/modules/crm/features/leads/components/lead-summary-card";
import { AssignLeadModal } from "@/modules/crm/features/leads/components/assign-lead-modal";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { QuickActionsCard } from "@/modules/crm/features/leads/components/quick-actions-card";
import { ScheduleFollowUpModal } from "@/modules/crm/features/leads/components/schedule-follow-up-modal";
import { WonConfirmDialog } from "@/modules/crm/features/leads/components/won-confirm-dialog";
import { LostConfirmDialog } from "@/modules/crm/features/leads/components/lost-confirm-dialog";
import { DiscussionNotes } from "@/modules/crm/features/leads/components/discussion-notes";
import { EmailThread } from "@/modules/crm/features/leads/components/email-thread";
import { LeadAttachmentsModal } from "@/modules/crm/features/leads/components/lead-attachments-modal";
import { ActiveCallPanel } from "@/modules/crm/features/leads/components/active-call-panel";
import { LeadTimelinePanel } from "@/modules/crm/features/leads/components/lead-timeline-panel";
import { LeadFilesCard } from "@/modules/crm/features/leads/components/lead-files-card";
import { EditLeadDrawer } from "@/modules/crm/features/leads/components/edit-lead-drawer";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";

export function LeadDetailPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [followUpOpen, setFollowUpOpen] = useState(false);
  const [wonOpen, setWonOpen] = useState(false);
  const [lostOpen, setLostOpen] = useState(false);
  const [attachmentsOpen, setAttachmentsOpen] = useState(false);
  const { lead, editableLead, quickActions, isLoading, isError } = useLeadDetail(params.id);
  // Live ownership for the lead on screen: patches the owner in place if someone
  // else reassigns it, and unsubscribes a BD who loses it. Subscribed here (not in
  // the list) because this is the one view showing a single lead's stream.
  const { lostAccess } = useLeadChannel(params.id);
  const activities = useLeadActivities(params.id);
  const deleteLead = useDeleteLead();
  const updateLead = useUpdateLead();
  const { hasMinRole, can } = usePermissions();
  const canAssign = hasMinRole("crm_admin");
  // Mirror the backend authorization: DELETE /leads/{id} requires crm.leads.delete (BDMs lack it).
  const canDelete = can("crm.leads.delete");
  // Attachments are not permission-gated in the UI: Admin and BD have identical rights over the
  // files on a lead they can already open, so the modal renders the same controls for both.
  // Shared with the BD table's row action — one hook, one endpoint, one PDF implementation.
  const { exportLeadPdf, exportingId } = useExportLeadPdf();

  const handleDelete = () => {
    deleteLead.mutate(params.id, {
      onSuccess: () => {
        setConfirmOpen(false);
        router.push("/zerem/leads");
      },
    });
  };

  // Checked before the loading and error branches on purpose. Losing the lead
  // triggers a refetch that necessarily 403s, so without this the viewer would see
  // a spinner and then a red "Failed to load lead details" — technically true and
  // completely unhelpful, since nothing failed. Say what happened instead.
  if (lostAccess) {
    return (
      <ErrorState
        tone="info"
        message="This lead has been reassigned. You no longer have permission to view it."
      />
    );
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-border border-t-primary" />
      </div>
    );
  }

  if (isError || !lead) {
    return <ErrorState message="Failed to load lead details" />;
  }

  return (
    <>
      <div className="flex flex-col gap-6 lg:gap-8">
        <LeadDetailHeader
          title={lead.business}
          subtitle={lead.industry}
          deal={lead.deal}
          outcome={lead.outcome}
          canAssign={canAssign}
          canDelete={canDelete}
          assigned={!!lead.assignedTo}
          onAssign={() => setAssignOpen(true)}
          onEdit={() => setDrawerOpen(true)}
          onDelete={() => setConfirmOpen(true)}
          onExportPdf={() => exportLeadPdf(lead.id, lead.business)}
          exportingPdf={exportingId === lead.id}
          onAttachments={() => setAttachmentsOpen(true)}
        />

        {/* Dominant left content region + a narrow right sidebar, matching the reference proportions.
            From xl the two columns share the SAME THREE ROWS (`grid-rows-subgrid` below), which is
            what keeps them in step: each column used to stack its own cards independently, so the
            45px by which Quick Actions outgrew the Lead Overview pushed the whole rail 44px out of
            alignment with the main column — the Activity Timeline started level with nothing, and
            the offset persisted down the page. Sharing rows costs no markup and no fixed heights:
            a row is as tall as the taller of the two cards in it, so every row STARTS on one line.
            Only the first row stretches its shorter card (Quick Actions beside the Lead Overview);
            everything below keeps its own height — Internal Notes and Call (`xl:items-start` below),
            the Activity Timeline and Files & Downloads (`xl:self-start` in their cards).

            Deliberately xl and not lg, even though the two columns appear at lg. Between lg and xl
            the Notes/Call pair stacks instead of sitting side by side, which makes the middle row
            766px tall against a 410px timeline — sharing rows there would buy alignment at the
            price of a 356px hole inside the Activity Timeline. That band keeps its existing
            independent columns. */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[76fr_24fr] xl:grid-rows-[auto_auto_auto]">
          <div className="flex flex-col gap-6 xl:row-span-3 xl:grid xl:grid-rows-subgrid">
            <LeadSummaryCard lead={lead} />
            {/* Uploading stays behind the header's Attachment action; the page surfaces files only
                as the read-only Files & Downloads card in the right rail. */}
            {/* Internal Notes + the Calling Workspace share a row (reference layout): Notes dominant
                (~71%), Active Call a narrow console (~29%). Below xl the two stack (Notes above Call)
                — the tablet layout — and collapse into the single mobile column. */}
            {/* Side by side from xl, but each card at its OWN height (`xl:items-start`). Grid items
                default to `stretch`, which made the shorter card — usually Call, beside a long note
                history — as tall as the other and left a large empty block inside it. They share a
                top edge, not a bottom one. `items-start` is block-axis only: the 71/29 split and each
                card's full column width are unchanged. Notes keeps its own internal scrolling
                (`max-h-96`). Stacked below xl, each card sits in its own row at its natural height,
                as before. */}
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[71fr_29fr] xl:items-start">
              <DiscussionNotes leadId={params.id} />
              <ActiveCallPanel leadId={params.id} />
            </div>
            {/* Email Conversation (outbound + inbound) with its reply box, full width of the main
                column and BELOW the Notes/Call row. Reads GET /leads/{id}/emails and sends through
                the existing Outreach pipeline — the same one the Follow-ups workspace uses. */}
            <EmailThread leadId={params.id} />
          </div>
          {/* Same three shared rows as the column above, so Quick Actions / Activity Timeline /
              Files line up with Lead Overview / Notes+Call / Email Conversation. Below xl this is
              an ordinary flex column again and the cards simply stack. */}
          <div className="flex flex-col gap-6 xl:row-span-3 xl:grid xl:grid-rows-subgrid">
            <QuickActionsCard
              actions={quickActions}
              onSelect={(key) => {
                // "Draft & send email" → the existing Outreach module, carrying this lead. No email
                // composer is duplicated in Lead Detail (single source of truth = Outreach).
                if (key === "email") router.push(`/zerem/follow-ups?lead=${params.id}`);
                // "Schedule follow-up" → the approved modal (existing Planner backend).
                else if (key === "follow-up") setFollowUpOpen(true);
              }}
              status={lead.status}
              onStatusChange={(status) =>
                updateLead.mutate({ id: params.id, payload: { status: status as typeof lead.status } })
              }
              statusPending={updateLead.isPending}
              onWon={() => setWonOpen(true)}
              onLost={() => setLostOpen(true)}
              wonDeal={lead.deal}
              isLost={lead.lost}
            />
            <LeadTimelinePanel
              leadId={params.id}
              activities={activities.data}
              activitiesLoading={activities.isLoading}
              activitiesError={activities.isError}
            />
            {/* Documents get their own box BELOW the timeline panel rather than being folded into
                it: a file is not an event, and mixing the two would make the Activity feed unusable
                as a history. Its five-row window keeps the rail's height stable. */}
            <LeadFilesCard leadId={params.id} />
          </div>
        </div>
      </div>

      {/* Deliberately NOT keyed by lead id. It holds no local state — `open` lives here and the file
          list comes from a query already keyed per lead — so a remount would buy nothing, and the
          key collided with AssignLeadModal's below (both resolve to the same lead id, and sibling
          keys must be unique). */}
      <LeadAttachmentsModal
        open={attachmentsOpen}
        onOpenChange={setAttachmentsOpen}
        leadId={params.id}
        leadName={lead.business}
      />

      <EditLeadDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        lead={editableLead}
        canDelete={canDelete}
        onDeleted={() => router.push("/zerem/leads")}
      />

      {canAssign && editableLead ? (
        <AssignLeadModal
          key={editableLead.id}
          open={assignOpen}
          onOpenChange={setAssignOpen}
          lead={editableLead}
        />
      ) : null}

      <ScheduleFollowUpModal
        open={followUpOpen}
        onOpenChange={setFollowUpOpen}
        leadId={params.id}
        leadName={lead.business}
      />

      <WonConfirmDialog
        open={wonOpen}
        onOpenChange={setWonOpen}
        leadId={params.id}
        leadName={lead.business}
        defaultAmount={lead.amount}
        alreadyWon={!!lead.deal}
      />

      {/* Both read the raw outcome flags, never `lead.outcome` — that one resolves Won first, so a
          lead marked both would report "won" and the Lost dialog would never see its own state. */}
      <LostConfirmDialog
        open={lostOpen}
        onOpenChange={setLostOpen}
        leadId={params.id}
        leadName={lead.business}
        alreadyLost={lead.lost}
        alreadyWon={!!lead.deal}
      />

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Delete lead?"
        description={`"${lead.business}" will be deleted. This can't be undone.`}
        confirmLabel="Delete Lead"
        onConfirm={handleDelete}
        loading={deleteLead.isPending}
        destructive
      />
    </>
  );
}
