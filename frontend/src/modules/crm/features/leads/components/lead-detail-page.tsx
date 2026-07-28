"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useLeadDetail } from "@/modules/crm/features/leads/hooks/use-lead-detail";
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
import { ActiveCallPanel } from "@/modules/crm/features/leads/components/active-call-panel";
import { LeadTimelinePanel } from "@/modules/crm/features/leads/components/lead-timeline-panel";
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
  const { lead, editableLead, quickActions, isLoading, isError } = useLeadDetail(params.id);
  const activities = useLeadActivities(params.id);
  const deleteLead = useDeleteLead();
  const updateLead = useUpdateLead();
  const { hasMinRole, can } = usePermissions();
  const canAssign = hasMinRole("crm_admin");
  // Mirror the backend authorization: DELETE /leads/{id} requires leads.delete (BDMs lack it).
  const canDelete = can("leads.delete");
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
          canAssign={canAssign}
          canDelete={canDelete}
          assigned={!!lead.assignedTo}
          onAssign={() => setAssignOpen(true)}
          onEdit={() => setDrawerOpen(true)}
          onDelete={() => setConfirmOpen(true)}
          onExportPdf={() => exportLeadPdf(lead.id, lead.business)}
          exportingPdf={exportingId === lead.id}
        />

        {/* Dominant left content region + a narrow right sidebar, matching the reference proportions. */}
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[76fr_24fr] lg:gap-6">
          <div className="flex flex-col gap-6">
            <LeadSummaryCard lead={lead} />
            {/* Internal Notes + the Calling Workspace share a row (reference layout): Notes dominant
                (~71%), Active Call a narrow console (~29%). Stretches to equal height. Below xl the two
                stack (Notes above Call) — the tablet layout — and collapse into the single mobile column. */}
            <div className="grid grid-cols-1 gap-6 xl:grid-cols-[71fr_29fr]">
              <DiscussionNotes leadId={params.id} />
              <ActiveCallPanel leadId={params.id} />
            </div>
          </div>
          <div className="flex flex-col gap-6">
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
            />
            <LeadTimelinePanel
              leadId={params.id}
              activities={activities.data}
              activitiesLoading={activities.isLoading}
              activitiesError={activities.isError}
            />
          </div>
        </div>
      </div>

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
      />

      <LostConfirmDialog
        open={lostOpen}
        onOpenChange={setLostOpen}
        leadId={params.id}
        leadName={lead.business}
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
