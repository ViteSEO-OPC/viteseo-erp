import Link from "next/link";
import { ArrowLeft, Download, Edit3, Trash2, UserPlus } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";

type Props = {
  title: string;
  subtitle: string;
  /** Assign/Reassign is Admin/SA only. */
  canAssign?: boolean;
  /** Delete requires the leads.delete permission (BDMs lack it). */
  canDelete?: boolean;
  assigned?: boolean;
  onAssign?: () => void;
  onEdit?: () => void;
  onDelete?: () => void;
};

export function LeadDetailHeader({ title, subtitle, canAssign, canDelete, assigned, onAssign, onEdit, onDelete }: Props) {
  return (
    <div>
      <Link
        href="/zerem/leads"
        className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <ArrowLeft className="h-4 w-4" />
        Back to Leads
      </Link>

      <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-3xl font-bold leading-tight text-card-foreground">
            {title}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
        </div>

        <div className="flex items-center gap-2 shrink-0">
          {canAssign ? (
            <Button variant="outline" size="sm" onClick={onAssign}>
              <UserPlus className="h-4 w-4" />
              {assigned ? "Reassign" : "Assign"}
            </Button>
          ) : null}
          {canDelete ? (
            <Button
              variant="outline"
              size="sm"
              onClick={onDelete}
              className="border-destructive/40 text-destructive hover:bg-destructive/10"
            >
              <Trash2 className="h-4 w-4" />
              Delete Lead
            </Button>
          ) : null}
          {/* Export PDF — presentation-only (disabled). PDF export is not wired before the demo;
              this is a visual placeholder and performs no action (mirrors the Won/Lose pattern). */}
          <Button
            variant="outline"
            size="sm"
            disabled
            title="PDF export not enabled yet"
            className="cursor-not-allowed opacity-60"
          >
            <Download className="h-4 w-4" />
            Export PDF
          </Button>
          <Button variant="default" size="sm" onClick={onEdit}>
            <Edit3 className="h-4 w-4" />
            Edit Lead
          </Button>
        </div>
      </div>
    </div>
  );
}
