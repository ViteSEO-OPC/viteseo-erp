"use client";

import { useMemo, useState } from "react";
import { Plus } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import { SearchInput } from "@/modules/crm/components/shared/search-input/search-input";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { EmptyState } from "@/modules/crm/components/shared/empty-state/empty-state";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { cn } from "@/modules/crm/lib/utils";
import { EmailTemplateTable } from "@/modules/crm/features/email-templates/components/email-template-table";
import {
  EmailTemplateEditor,
  type EditorTab,
} from "@/modules/crm/features/email-templates/components/email-template-editor";
import {
  useDeleteEmailTemplate,
  useEmailTemplatesAdmin,
  useUpdateEmailTemplate,
} from "@/modules/crm/features/email-templates/hooks/use-email-templates-admin";
import { KIND_LABELS } from "@/modules/crm/features/email-templates/lib/email-template-text";
import type {
  EmailTemplate,
  TemplateKind,
} from "@/modules/crm/features/email-templates/types/email-template-types";

type KindFilter = "all" | TemplateKind;
type StatusFilter = "all" | "active" | "inactive";
type EditorState = { template: EmailTemplate | null; tab: EditorTab };

const STATUS_LABELS: Record<StatusFilter, string> = {
  all: "All statuses",
  active: "Active",
  inactive: "Inactive",
};

/**
 * Settings → Email Templates: the reusable subjects and bodies behind the Outreach composer's
 * Purpose + Tone dropdowns and the n8n automation sends.
 *
 * List, search and filter; create and edit in a side sheet with a live preview; activate, deactivate
 * and delete in place. The server owns every rule that matters — the catalog, one template per purpose
 * and tone, and never leaving an automation purpose without an active template — and this page shows
 * its refusals rather than second-guessing them.
 */
export function EmailTemplatesPage() {
  const { templates, catalog, isLoading, isError, retry } = useEmailTemplatesAdmin();
  const update = useUpdateEmailTemplate();
  const remove = useDeleteEmailTemplate();

  const [kind, setKind] = useState<KindFilter>("all");
  const [status, setStatus] = useState<StatusFilter>("all");
  const [search, setSearch] = useState("");
  const [editor, setEditor] = useState<EditorState | null>(null);
  const [pendingDelete, setPendingDelete] = useState<EmailTemplate | null>(null);
  const [pendingDeactivate, setPendingDeactivate] = useState<EmailTemplate | null>(null);

  const automationIds = useMemo(() => new Set(catalog?.automationTemplateIds ?? []), [catalog]);

  const counts: Record<KindFilter, number> = useMemo(
    () => ({
      all: templates.length,
      composer: templates.filter((t) => t.kind === "composer").length,
      automation: templates.filter((t) => t.kind === "automation").length,
    }),
    [templates],
  );

  const visible = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return templates.filter(
      (t) =>
        (kind === "all" || t.kind === kind) &&
        (status === "all" || (status === "active") === t.isActive) &&
        (needle === "" || [t.subject, t.purpose, t.tone, t.body].some((text) => text.toLowerCase().includes(needle))),
    );
  }, [templates, kind, status, search]);

  function setActive(template: EmailTemplate, active: boolean) {
    // Switching off a template automated emails rely on is worth a second look first.
    if (!active && automationIds.has(template.id)) {
      setPendingDeactivate(template);
      return;
    }
    update.mutate({ id: template.id, is_active: active });
  }

  const togglingId = update.isPending ? (update.variables?.id ?? null) : null;

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 className="text-2xl font-bold text-foreground">Email Templates</h2>
            {!isLoading && !isError ? (
              <span className="text-sm font-medium text-muted-foreground">
                {templates.length} template{templates.length === 1 ? "" : "s"}
              </span>
            ) : null}
          </div>
          <p className="text-sm text-muted-foreground">
            Reusable subjects and bodies for Outreach and automated emails. Placeholders such as{" "}
            <code className="rounded bg-muted px-1 font-mono text-xs">{"{{first_name}}"}</code> are filled in for
            each lead when the email is written.
          </p>
        </div>
        <Button className="shrink-0" onClick={() => setEditor({ template: null, tab: "edit" })} disabled={!catalog}>
          <Plus aria-hidden />
          New template
        </Button>
      </header>

      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div
          role="group"
          aria-label="Template type"
          className="inline-flex w-fit flex-wrap items-center gap-1 rounded-xl border border-border bg-card p-1"
        >
          {(["all", "composer", "automation"] as const).map((id) => (
            <button
              key={id}
              type="button"
              aria-pressed={kind === id}
              onClick={() => setKind(id)}
              className={cn(
                "flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
                kind === id ? "bg-muted text-foreground shadow-sm" : "text-muted-foreground hover:text-foreground",
              )}
            >
              {id === "all" ? "All" : KIND_LABELS[id]}
              <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-muted px-1.5 text-[11px] font-semibold text-muted-foreground">
                {counts[id]}
              </span>
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Select value={status} onValueChange={(value) => setStatus((value as StatusFilter) ?? "all")}>
            <SelectTrigger className="w-40" aria-label="Status">
              <SelectValue>{STATUS_LABELS[status]}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(STATUS_LABELS) as StatusFilter[]).map((id) => (
                <SelectItem key={id} value={id}>
                  {STATUS_LABELS[id]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <SearchInput
            className="w-full sm:w-64"
            value={search}
            onChange={setSearch}
            placeholder="Search templates"
          />
        </div>
      </div>

      <Card className="border border-border bg-card p-0 shadow-card">
        {isError ? (
          <ErrorState className="p-6" message="Could not load email templates." onRetry={() => void retry()} />
        ) : isLoading ? (
          <div className="flex flex-col gap-3 p-6">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : templates.length === 0 ? (
          <EmptyState className="p-6" message="No email templates yet. Create one to offer it in Outreach." />
        ) : visible.length === 0 ? (
          <EmptyState className="p-6" message="No templates match these filters." />
        ) : (
          <EmailTemplateTable
            templates={visible}
            automationIds={automationIds}
            togglingId={togglingId}
            onEdit={(template) => setEditor({ template, tab: "edit" })}
            onPreview={(template) => setEditor({ template, tab: "preview" })}
            onDelete={setPendingDelete}
            onSetActive={setActive}
          />
        )}
      </Card>

      {editor && catalog ? (
        <EmailTemplateEditor
          key={editor.template?.id ?? "new"}
          template={editor.template}
          initialTab={editor.tab}
          catalog={catalog}
          onClose={() => setEditor(null)}
        />
      ) : null}

      <ConfirmDialog
        open={pendingDelete !== null}
        onOpenChange={(open) => !open && setPendingDelete(null)}
        title="Delete this template?"
        description={
          pendingDelete
            ? `“${pendingDelete.subject}” (${pendingDelete.purpose} · ${pendingDelete.tone}) will no longer be offered. It is kept on record but cannot be restored from this screen. The only active template for an automation purpose cannot be deleted.`
            : undefined
        }
        confirmLabel="Delete"
        destructive
        loading={remove.isPending}
        onConfirm={() =>
          pendingDelete && remove.mutate(pendingDelete.id, { onSettled: () => setPendingDelete(null) })
        }
      />

      <ConfirmDialog
        open={pendingDeactivate !== null}
        onOpenChange={(open) => !open && setPendingDeactivate(null)}
        title="Deactivate an automation template?"
        description={
          pendingDeactivate
            ? `Automated emails use “${pendingDeactivate.subject}”. If it is the only active template for its purpose, the change is refused so those sends keep working.`
            : undefined
        }
        confirmLabel="Deactivate"
        loading={update.isPending}
        onConfirm={() =>
          pendingDeactivate &&
          update.mutate(
            { id: pendingDeactivate.id, is_active: false },
            { onSettled: () => setPendingDeactivate(null) },
          )
        }
      />
    </div>
  );
}
