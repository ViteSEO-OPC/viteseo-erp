"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useLeadDetail } from "@/modules/crm/features/leads/hooks/use-lead-detail";
import { useLeadActivities } from "@/modules/crm/features/leads/hooks/use-lead-activities";
import { useDeleteLead, useScheduleFollowUp } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import { LeadDetailHeader } from "@/modules/crm/features/leads/components/lead-detail-header";
import { LeadSummaryCard } from "@/modules/crm/features/leads/components/lead-summary-card";
import { AssignLeadModal } from "@/modules/crm/features/leads/components/assign-lead-modal";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { QuickActionsCard } from "@/modules/crm/features/leads/components/quick-actions-card";
import { EmailComposeModal } from "@/modules/crm/features/leads/components/email-compose-modal";
import { DiscussionNotes } from "@/modules/crm/features/leads/components/discussion-notes";
import { ActivityTimeline } from "@/modules/crm/features/leads/components/activity-timeline";
import { EditLeadDrawer } from "@/modules/crm/features/leads/components/edit-lead-drawer";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";

export function LeadDetailPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [composeOpen, setComposeOpen] = useState(false);
  const { lead, editableLead, quickActions, isLoading, isError } = useLeadDetail(params.id);
  const activities = useLeadActivities(params.id);
  const deleteLead = useDeleteLead();
  const scheduleFollowUp = useScheduleFollowUp();
  const { hasMinRole, can } = usePermissions();
  const canAssign = hasMinRole("crm_admin");
  // Mirror the backend authorization: DELETE /leads/{id} requires leads.delete (BDMs lack it).
  // Gate every Delete affordance so the UI never offers an action the API will 403.
  const canDelete = can("leads.delete");
  // Outreach email + Schedule follow-up both reuse the follow-ups.create permission
  // (POST /leads/{id}/emails and POST /leads/{id}/follow-ups authorize on it).
  const canEmail = can("follow-ups.create");

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
      <div className="flex flex-col gap-6">
        <LeadDetailHeader
          title={lead.business}
          subtitle="Restaurant · Makati"
          canAssign={canAssign}
          canDelete={canDelete}
          assigned={!!lead.assignedTo}
          onAssign={() => setAssignOpen(true)}
          onEdit={() => setDrawerOpen(true)}
          onDelete={() => setConfirmOpen(true)}
        />

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[62fr_38fr]">
          <div className="flex flex-col gap-6">
            <LeadSummaryCard lead={lead} />
            <DiscussionNotes leadId={params.id} />
          </div>
          <div className="flex flex-col gap-6">
            <QuickActionsCard
              actions={quickActions}
              onSelect={(key) => {
                if (key === "email" && canEmail) setComposeOpen(true);
                // "Schedule follow-up" → the single authoritative FollowUpScheduler flow
                // (POST /leads/{id}/follow-ups). Guard against double-create while in flight.
                else if (key === "follow-up" && canEmail && !scheduleFollowUp.isPending) {
                  scheduleFollowUp.mutate({ leadId: params.id });
                }
                // "call" / "contacted" remain deferred (no-op).
              }}
            />
            <ActivityTimeline
              events={activities.data}
              isLoading={activities.isLoading}
              isError={activities.isError}
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

      {canEmail ? (
        <EmailComposeModal
          key={lead.id}
          open={composeOpen}
          onOpenChange={setComposeOpen}
          leadId={params.id}
          leadName={lead.business}
          leadEmail={lead.email}
        />
      ) : null}

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
