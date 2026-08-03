"use client";

import { useState } from "react";
import { FilePen, Rocket, Trash2 } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { LEAD_SCORING } from "@/modules/crm/features/business-rules/types/business-rules-types";
import {
  useActiveConfig,
  useAudit,
  useDraft,
  useVersions,
} from "@/modules/crm/features/business-rules/hooks/use-business-rules-data";
import {
  useDiscardDraft,
  useEnsureDraft,
  usePublish,
  useReorderRules,
  useRollback,
  useSetRuleEnabled,
  useUpdateRule,
} from "@/modules/crm/features/business-rules/hooks/use-business-rules-mutations";
import { RulesTable } from "@/modules/crm/features/business-rules/components/rules-table";
import { PublishDialog } from "@/modules/crm/features/business-rules/components/publish-dialog";
import { RollbackDialog } from "@/modules/crm/features/business-rules/components/rollback-dialog";
import { VersionHistory } from "@/modules/crm/features/business-rules/components/version-history";
import { AuditHistory } from "@/modules/crm/features/business-rules/components/audit-history";
import type { BusinessRuleVersion } from "@/modules/crm/features/business-rules/types/business-rules-types";

type Tab = "configuration" | "versions" | "audit";

const TABS: { id: Tab; label: string }[] = [
  { id: "configuration", label: "Configuration" },
  { id: "versions", label: "Version History" },
  { id: "audit", label: "Audit Log" },
];

function fmt(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString() : "—";
}

/**
 * `module` selects which configurable computation this surface administers. It defaults to
 * lead_scoring so the existing Settings → Business Rules page is unchanged; Performance Management
 * passes "kpi" to render the KPI definition catalog through the SAME components rather than a second
 * CRUD implementation.
 *
 * `managePermission` follows, because the two modules are gated by different permissions: KPI
 * definitions require crm.performance.manage on top of the shared view permission.
 *
 * `showHeader` is off when an outer page already titles the surface (Performance Management renders
 * its own heading and tab strip), so the KPI tab does not stack a second "Business Rules" title.
 */
export function BusinessRulesPage({
  module = LEAD_SCORING,
  managePermission = "crm.business_rules.manage",
  showHeader = true,
}: {
  module?: string;
  managePermission?: string;
  showHeader?: boolean;
} = {}) {
  const { can } = usePermissions();
  const canView = can("crm.business_rules.view");
  const canManage = can(managePermission);

  const [tab, setTab] = useState<Tab>("configuration");
  const [publishOpen, setPublishOpen] = useState(false);
  const [discardOpen, setDiscardOpen] = useState(false);
  const [rollbackTarget, setRollbackTarget] = useState<BusinessRuleVersion | null>(null);

  const active = useActiveConfig(module);
  const draftQuery = useDraft(module);
  const versions = useVersions(module);
  const audit = useAudit(module);

  const ensureDraft = useEnsureDraft(module);
  const updateRule = useUpdateRule(module);
  const setEnabled = useSetRuleEnabled(module);
  const reorder = useReorderRules(module);
  const discard = useDiscardDraft(module);
  const publish = usePublish(module);
  const rollback = useRollback(module);

  if (!canView) {
    return <AlertBanner variant="warning" message="You don’t have permission to view Business Rules." />;
  }

  if (active.isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (active.isError || !active.data) {
    return <ErrorState message="Couldn’t load Business Rules." onRetry={() => active.refetch()} />;
  }

  const activeVersion = active.data;
  const draft = draftQuery.data ?? null;
  const editing = draft !== null;
  const shownRules = editing ? draft.rules : activeVersion.rules;
  const busy =
    ensureDraft.isPending ||
    updateRule.isPending ||
    setEnabled.isPending ||
    reorder.isPending ||
    discard.isPending;

  const handleMove = (ruleKey: string, direction: "up" | "down") => {
    if (!draft) return;
    const ordered = [...draft.rules].sort((a, b) => a.displayOrder - b.displayOrder).map((r) => r.ruleKey);
    const i = ordered.indexOf(ruleKey);
    const j = direction === "up" ? i - 1 : i + 1;
    if (i < 0 || j < 0 || j >= ordered.length) return;
    [ordered[i], ordered[j]] = [ordered[j], ordered[i]];
    reorder.mutate(ordered);
  };

  return (
    <div className="flex flex-col gap-6">
      {showHeader && (
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 className="text-2xl font-bold text-foreground">Business Rules</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              Configure the Lead Scoring rules. Computed results (Lead Scores, BD Performance) are never edited
              here — they recompute automatically when a change is published.
            </p>
          </div>
        </header>
      )}

      {/* Tab bar */}
      <div className="flex gap-1 border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            className={cn(
              "px-3 py-2 text-sm font-medium transition-colors",
              tab === t.id
                ? "border-b-2 border-teal-500 text-teal-700"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "configuration" && (
        <div className="flex flex-col gap-4">
          {/* Active version summary */}
          <Card className="flex flex-wrap items-center justify-between gap-4 p-4">
            <div className="flex items-center gap-4">
              <div>
                <p className="text-xs uppercase tracking-wide text-muted-foreground">Active version</p>
                <p className="text-lg font-semibold text-foreground">v{activeVersion.versionNumber}</p>
              </div>
              <Badge variant="outline">Spec {activeVersion.computationSpecVersion}</Badge>
              <span className="text-sm text-muted-foreground">Published {fmt(activeVersion.publishedAt)}</span>
            </div>
            {canManage && !editing && (
              <Button size="sm" onClick={() => ensureDraft.mutate()} disabled={busy}>
                <FilePen className="mr-1.5 h-4 w-4" /> Edit configuration
              </Button>
            )}
          </Card>

          {/* Draft banner */}
          {editing && (
            <AlertBanner
              variant="info"
              message={`Unpublished draft — version ${draft.versionNumber}. Publish to apply your changes, or discard the draft.`}
            >
              <div className="mt-2 flex flex-wrap gap-2">
                <Button size="sm" onClick={() => setPublishOpen(true)} disabled={publish.isPending}>
                  <Rocket className="mr-1.5 h-4 w-4" /> Publish
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setDiscardOpen(true)}
                  disabled={discard.isPending}
                >
                  <Trash2 className="mr-1.5 h-4 w-4" /> Discard draft
                </Button>
              </div>
            </AlertBanner>
          )}

          <RulesTable
            rules={shownRules}
            editable={editing && canManage}
            busy={busy}
            onUpdatePoints={(ruleKey, pointValue) => updateRule.mutate({ ruleKey, payload: { point_value: pointValue } })}
            onToggleEnabled={(ruleKey, enabled) => setEnabled.mutate({ ruleKey, enabled })}
            onMove={handleMove}
          />
        </div>
      )}

      {tab === "versions" && (
        <VersionHistory
          versions={versions.data ?? []}
          canManage={canManage}
          onRollback={(v) => setRollbackTarget(v)}
        />
      )}

      {tab === "audit" && <AuditHistory entries={audit.data ?? []} />}

      {/* Dialogs */}
      {editing && (
        <PublishDialog
          open={publishOpen}
          onOpenChange={setPublishOpen}
          draftVersion={draft.versionNumber}
          loading={publish.isPending}
          onConfirm={(reason) =>
            publish.mutate(reason, { onSuccess: () => setPublishOpen(false) })
          }
        />
      )}

      <ConfirmDialog
        open={discardOpen}
        onOpenChange={setDiscardOpen}
        title="Discard this draft?"
        description="All unpublished edits will be lost. The active configuration is unchanged."
        confirmLabel="Discard"
        destructive
        loading={discard.isPending}
        onConfirm={() => discard.mutate(undefined, { onSuccess: () => setDiscardOpen(false) })}
      />

      <RollbackDialog
        open={rollbackTarget !== null}
        onOpenChange={(v) => !v && setRollbackTarget(null)}
        target={rollbackTarget}
        loading={rollback.isPending}
        onConfirm={(targetVersion, reason) =>
          rollback.mutate({ targetVersion, reason }, { onSuccess: () => setRollbackTarget(null) })
        }
      />
    </div>
  );
}
