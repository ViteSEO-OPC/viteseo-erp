import { Globe, Check, X, ExternalLink } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import type {
  NearbyPriority,
  WebsiteSignals,
  WebsiteSummary,
} from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

/**
 * Website Intelligence panel — the BD-facing, explainable view of a discovered business's website.
 *
 * It answers, from REAL crawler evidence only: does the business have a website, which pages/features/
 * social channels were detected, which audited signals are missing, and *why* the prospect is High /
 * Medium / Low priority. Nothing here is fabricated: a signal appears under Detected or Missing only if
 * we actually audit it, and the priority rationale is derived from the same signals the backend used —
 * never a hardcoded per-business rule. (Provider provenance and any confidence score are intentionally
 * NOT shown; see the Nearby module's provider-agnostic invariant.)
 */

// The signals we audit today, in display order. Detected = true, Missing = false — one catalogue drives
// both columns so they can never disagree. Extended signals (Menu, Privacy, Booking, TikTok, …) are a
// separate Post-RC pipeline milestone and are deliberately absent until the crawler actually detects them.
const SIGNAL_CATALOG: { key: keyof WebsiteSignals; label: string }[] = [
  { key: "reachable", label: "Homepage" },
  { key: "https", label: "HTTPS" },
  { key: "about", label: "About" },
  { key: "contact", label: "Contact" },
  { key: "services", label: "Services" },
  { key: "careers", label: "Careers" },
  { key: "blog", label: "Blog" },
  { key: "emailDetected", label: "Email" },
  { key: "phoneDetected", label: "Phone" },
  { key: "facebook", label: "Facebook" },
  { key: "instagram", label: "Instagram" },
  { key: "linkedin", label: "LinkedIn" },
  { key: "youtube", label: "YouTube" },
];

const STATUS_STYLE: Record<string, { label: string; cls: string }> = {
  "has-website": { label: "Strong website", cls: "bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300" },
  "needs-redesign": { label: "Needs redesign", cls: "bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300" },
  "no-website": { label: "No website", cls: "bg-muted text-muted-foreground" },
};

const PRIORITY_STYLE: Record<NearbyPriority, { label: string; dot: string; text: string }> = {
  high: { label: "High priority", dot: "bg-red-500", text: "text-red-600" },
  medium: { label: "Medium priority", dot: "bg-amber-400", text: "text-amber-600" },
  low: { label: "Low priority", dot: "bg-blue-500", text: "text-blue-600" },
};

export function WebsiteIntelPanel({ website, priority }: { website?: WebsiteSummary; priority: NearbyPriority }) {
  if (!website) return null;

  const s = website.signals;
  const detected = s ? SIGNAL_CATALOG.filter((c) => s[c.key]) : [];
  const missing = s ? SIGNAL_CATALOG.filter((c) => !s[c.key]) : [];
  const status = website.status ? STATUS_STYLE[website.status] : undefined;
  const p = PRIORITY_STYLE[priority];

  return (
    <div className="flex flex-col gap-3">
      {/* Heading */}
      <div className="flex items-center gap-1.5">
        <Globe className="h-3.5 w-3.5 text-teal-600" aria-hidden />
        <h4 className="text-xs font-bold uppercase tracking-[0.06em] text-card-foreground">Website Intelligence</h4>
      </div>

      {/* Website URL (or Not found) */}
      <div className="flex flex-col gap-0.5">
        <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Website</span>
        {website.url ? (
          <a
            href={website.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 truncate text-xs font-medium text-teal-700 hover:underline dark:text-teal-400"
            onClick={(e) => e.stopPropagation()}
          >
            <span className="truncate">{website.url}</span>
            <ExternalLink className="h-3 w-3 shrink-0" aria-hidden />
          </a>
        ) : (
          <span className="text-xs font-medium text-muted-foreground">Not found</span>
        )}
      </div>

      {/* Status pill + audit state */}
      <div className="flex flex-wrap items-center gap-1.5">
        <span
          className={cn(
            "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold",
            status?.cls ?? "bg-muted text-muted-foreground",
          )}
        >
          <Globe className="h-3 w-3" aria-hidden />
          {status?.label ?? "Website unknown"}
        </span>
        {!website.audited && (
          <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Not audited</span>
        )}
      </div>

      {/* Explained priority — why this business is High / Medium / Low, from the audit signals. */}
      <div className="rounded-lg border border-border-subtle bg-muted/40 p-2.5">
        <div className="flex items-center gap-1.5">
          <span className={cn("h-1.5 w-1.5 rounded-full", p.dot)} aria-hidden />
          <span className={cn("text-xs font-semibold", p.text)}>{p.label}</span>
        </div>
        <p className="mt-1 text-[11px] leading-relaxed text-muted-foreground">{priorityExplanation(website)}</p>
      </div>

      {/* Detected / Missing — only when the site has actually been audited. */}
      {s ? (
        <div className="flex flex-col gap-2">
          <SignalGroup
            heading="Detected"
            tone="detected"
            items={detected.map((c) => c.label)}
            empty="No signals detected on the homepage."
          />
          {missing.length > 0 && (
            <SignalGroup heading="Missing" tone="missing" items={missing.map((c) => c.label)} />
          )}
        </div>
      ) : (
        <p className="text-[11px] text-muted-foreground">
          Detailed signal audit pending — priority above reflects discovered website presence only.
        </p>
      )}
    </div>
  );
}

function SignalGroup({
  heading,
  tone,
  items,
  empty,
}: {
  heading: string;
  tone: "detected" | "missing";
  items: string[];
  empty?: string;
}) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
        {heading} · {items.length}
      </span>
      {items.length > 0 ? (
        <div className="flex flex-wrap gap-1">
          {items.map((label) => (
            <SignalChip key={label} label={label} tone={tone} />
          ))}
        </div>
      ) : (
        empty && <span className="text-[10px] text-muted-foreground">{empty}</span>
      )}
    </div>
  );
}

function SignalChip({ label, tone }: { label: string; tone: "detected" | "missing" }) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-0.5 rounded border px-1.5 py-0.5 text-[10px] font-medium",
        tone === "detected"
          ? "border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300"
          : "border-border-subtle bg-muted/60 text-muted-foreground",
      )}
    >
      {tone === "detected" ? <Check className="h-2.5 w-2.5" aria-hidden /> : <X className="h-2.5 w-2.5" aria-hidden />}
      {label}
    </span>
  );
}

/**
 * The reason a business is High / Medium / Low — derived from its Website Intelligence status and, for a
 * redesign candidate, the specific core essentials it lacks. Purely evidence-driven; no business names.
 */
function priorityExplanation(website: WebsiteSummary): string {
  const s = website.signals;
  switch (website.status) {
    case "no-website":
      return "No website was found for this business — the highest-opportunity prospect for a new web presence.";
    case "needs-redesign": {
      const gaps: string[] = [];
      if (s) {
        if (!s.https) gaps.push("HTTPS");
        if (!s.about) gaps.push("an About page");
        if (!s.contact) gaps.push("a Contact page");
        if (!s.services) gaps.push("a Services page");
      }
      return gaps.length > 0
        ? `The website is reachable but missing ${listify(gaps)} — a clear redesign opportunity.`
        : "The website is reachable but missing core business essentials — a redesign opportunity.";
    }
    case "has-website":
      return "The website is complete and healthy (secure, with the core business pages present) — lower redesign priority.";
    default:
      return "Not yet audited — this priority is provisional until a website audit runs.";
  }
}

function listify(items: string[]): string {
  if (items.length <= 1) return items.join("");
  if (items.length === 2) return `${items[0]} and ${items[1]}`;
  return `${items.slice(0, -1).join(", ")}, and ${items[items.length - 1]}`;
}
