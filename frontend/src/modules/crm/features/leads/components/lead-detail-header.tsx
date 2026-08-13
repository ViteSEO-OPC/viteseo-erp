import Link from "next/link";
import { ArrowLeft, BadgeCheck, Download, Edit3, Frown, Loader2, Paperclip, Trash2, UserPlus } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { cn } from "@/modules/crm/lib/utils";
import { formatDateTimeLong } from "@/modules/crm/lib/formatters";
import type { LeadOutcome } from "@/modules/crm/features/leads/components/lead-score-ring";
import type { LeadDeal } from "@/modules/crm/features/leads/types/lead-detail-types";

// Header outcome badge — the same glyph + tint pairing the score ring uses, so Won and Lost read
// identically wherever they appear on the page.
const OUTCOME_BADGE = {
  won: {
    label: "Won",
    Icon: BadgeCheck,
    tone: "bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-400",
    title: "This lead has been converted into a deal",
  },
  lost: {
    label: "Lost",
    Icon: Frown,
    tone: "bg-destructive/10 text-destructive",
    title: "This lead has been marked as a lost opportunity",
  },
} as const;

type Props = {
  title: string;
  subtitle: string;
  /**
   * The lead's Won conversion, or null when it has none. Read-only projection of the Deal; it
   * supplies the realized amount and conversion date shown under the title.
   */
  deal?: LeadDeal | null;
  /**
   * Resolved outcome driving the badge. Won deliberately leaves the Lead Status, the estimate, and
   * the score alone, so without this the page states the outcome nowhere but the score ring.
   */
  outcome?: LeadOutcome | null;
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
  deal,
  outcome,
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
  const badge = outcome ? OUTCOME_BADGE[outcome] : null;

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
          <div className="flex flex-wrap items-center gap-2.5">
            <h1 className="text-3xl font-bold leading-tight text-card-foreground">
              {title}
            </h1>
            {badge ? (
              <span
                className={cn(
                  "inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em]",
                  badge.tone,
                )}
                title={badge.title}
              >
                <badge.Icon className="h-3.5 w-3.5" aria-hidden />
                {badge.label}
              </span>
            ) : null}
          </div>
          <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
          {/* Realized deal value + when it converted. Peso formatting matches the sibling Amount
              field on the Summary card, so the estimate and the realized value read consistently
              while staying clearly labelled as different numbers. */}
          {deal ? (
            <p className="mt-1 text-sm font-medium text-emerald-700 dark:text-emerald-400">
              ₱{deal.amount.toLocaleString("en-PH")}
              {deal.convertedAt ? (
                <span className="font-normal text-muted-foreground">
                  {" · Converted "}
                  {formatDateTimeLong(deal.convertedAt)}
                </span>
              ) : null}
            </p>
          ) : null}
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
