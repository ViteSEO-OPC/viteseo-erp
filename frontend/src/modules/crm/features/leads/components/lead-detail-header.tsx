import Link from "next/link";
import { ArrowLeft, Download, Edit3, Loader2, Paperclip, Trash2, UserPlus } from "lucide-react";
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
  /** Download this lead's PDF profile (server-rendered). */
  onExportPdf?: () => void;
  exportingPdf?: boolean;
  /** Open the Attachments modal for this lead. */
  onAttachments?: () => void;
};

export function LeadDetailHeader({
  title,
  subtitle,
  canAssign,
  canDelete,
  assigned,
  onAssign,
  onEdit,
  onDelete,
  onExportPdf,
  exportingPdf,
  onAttachments,
}: Props) {
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

        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
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
          {/* Server-rendered PDF profile of this lead — same endpoint the BD table row action uses. */}
          <Button
            variant="outline"
            size="sm"
            onClick={onExportPdf}
            disabled={exportingPdf}
            title="Download this lead's profile as a PDF"
          >
            {exportingPdf ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Download className="h-4 w-4" />
            )}
            Export PDF
          </Button>
          {/* Between Export PDF and Edit Lead — the approved header order. Opens the upload modal;
              the files themselves are listed by the Attachments card on the page. */}
          <Button
            variant="outline"
            size="sm"
            onClick={onAttachments}
            title="Upload and manage files for this lead"
          >
            <Paperclip className="h-4 w-4" />
            Attachment
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
