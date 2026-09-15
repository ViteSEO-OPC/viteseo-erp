"use client";

import { Lock, Palette } from "lucide-react";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Switch } from "@/modules/crm/components/ui/switch";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import { KIND_LABELS } from "@/modules/crm/features/email-templates/lib/email-template-text";
import type { EmailTemplate } from "@/modules/crm/features/email-templates/types/email-template-types";

const UPDATED = new Intl.DateTimeFormat("en-PH", { dateStyle: "medium", timeZone: "Asia/Manila" });

const ROW_ACTION =
  "rounded-sm font-medium text-teal-700 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:text-teal-400";

type Props = {
  templates: EmailTemplate[];
  automationIds: ReadonlySet<string>;
  /** The template whose active switch has a request in flight. */
  togglingId: string | null;
  onEdit: (template: EmailTemplate) => void;
  onPreview: (template: EmailTemplate) => void;
  onDelete: (template: EmailTemplate) => void;
  onSetActive: (template: EmailTemplate, active: boolean) => void;
};

/** The template list: subject first, with Edit · Preview · Delete under it, WordPress-style. */
export function EmailTemplateTable({
  templates,
  automationIds,
  togglingId,
  onEdit,
  onPreview,
  onDelete,
  onSetActive,
}: Props) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Template</TableHead>
          <TableHead className="w-44">Used by</TableHead>
          <TableHead className="w-40">Status</TableHead>
          <TableHead className="w-32">Updated</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {templates.map((template) => {
          // A composer row the automation endpoint also resolves — the MySQL Introduction collision.
          const alsoAutomation = template.kind === "composer" && automationIds.has(template.id);

          return (
            <TableRow key={template.id} className="align-top">
              <TableCell className="min-w-64 whitespace-normal">
                <button
                  type="button"
                  onClick={() => onEdit(template)}
                  className="rounded-sm text-left text-sm font-semibold break-words text-foreground hover:text-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring dark:hover:text-teal-400"
                >
                  {template.subject}
                </button>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                  <span>
                    {template.purpose} · {template.tone}
                  </span>
                  {template.isDesigned ? (
                    <Badge variant="outline" title="Built with the visual designer, shown in this email design">
                      <Palette aria-hidden />
                      {template.emailDesignName ?? "Designed"}
                    </Badge>
                  ) : null}
                </p>
                <div className="mt-1.5 flex items-center gap-2 text-xs text-muted-foreground">
                  <button type="button" className={ROW_ACTION} onClick={() => onEdit(template)}>
                    Edit
                  </button>
                  <span aria-hidden>·</span>
                  <button type="button" className={ROW_ACTION} onClick={() => onPreview(template)}>
                    Preview
                  </button>
                  <span aria-hidden>·</span>
                  <button
                    type="button"
                    className="rounded-sm font-medium text-destructive hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    onClick={() => onDelete(template)}
                  >
                    Delete
                  </button>
                </div>
              </TableCell>

              <TableCell className="whitespace-normal">
                <div className="flex flex-wrap gap-1">
                  <Badge variant={template.kind === "automation" ? "secondary" : "outline"}>
                    {template.kind === "automation" ? <Lock aria-hidden /> : null}
                    {KIND_LABELS[template.kind]}
                  </Badge>
                  {alsoAutomation ? (
                    <Badge variant="secondary" title="The automation endpoint also resolves this template.">
                      <Lock aria-hidden />
                      Automation
                    </Badge>
                  ) : null}
                </div>
              </TableCell>

              <TableCell>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={template.isActive}
                    disabled={togglingId === template.id}
                    onCheckedChange={(checked) => onSetActive(template, checked)}
                    aria-label={`${template.isActive ? "Deactivate" : "Activate"} ${template.purpose} · ${template.tone}`}
                  />
                  <span className={template.isActive ? "text-sm text-foreground" : "text-sm text-muted-foreground"}>
                    {template.isActive ? "Active" : "Inactive"}
                  </span>
                </div>
              </TableCell>

              <TableCell className="text-sm text-muted-foreground">
                {template.updatedAt ? UPDATED.format(new Date(template.updatedAt)) : "—"}
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}
