import { Globe, ShieldCheck, Mail, Phone, Share2 } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import type { WebsiteSummary } from "@/modules/crm/features/nearby-clients/types/nearby-clients-types";

const STATUS_STYLE: Record<string, { label: string; cls: string }> = {
  "has-website": { label: "Strong website", cls: "bg-green-100 text-green-700 dark:bg-green-950/50 dark:text-green-300" },
  "needs-redesign": { label: "Needs redesign", cls: "bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300" },
  "no-website": { label: "No website", cls: "bg-muted text-muted-foreground" },
  "website-unknown": { label: "Website not verified", cls: "bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300" },
  "website-unreachable": { label: "Website unreachable", cls: "bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300" },
};

/**
 * BD-facing Website Intelligence summary for a discovered business: a website-quality pill plus the
 * concrete signals detected (page structure, HTTPS, contactability, socials). Audit findings only —
 * these help BD spot stronger prospects; they never imply a score, priority, or status.
 */
export function WebsiteIntelBadge({ website, compact = false }: { website?: WebsiteSummary; compact?: boolean }) {
  if (!website) return null;

  const status = website.status ? STATUS_STYLE[website.status] : undefined;
  const s = website.signals;
  const socialCount = s ? [s.facebook, s.instagram, s.linkedin, s.youtube].filter(Boolean).length : 0;

  return (
    <div className="flex flex-col gap-1.5">
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
        {awaitingWebsiteCheck(website) && (
          <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Not audited</span>
        )}
      </div>

      {s && (
        <div className="flex flex-wrap items-center gap-1">
          {s.https && <Chip icon={<ShieldCheck className="h-3 w-3" />} label="HTTPS" />}
          {s.about && <Chip label="About" />}
          {s.contact && <Chip label="Contact" />}
          {s.services && <Chip label="Services" />}
          {s.careers && <Chip label="Careers" />}
          {s.blog && <Chip label="Blog" />}
          {s.emailDetected && <Chip icon={<Mail className="h-3 w-3" />} label="Email" />}
          {s.phoneDetected && <Chip icon={<Phone className="h-3 w-3" />} label="Phone" />}
          {socialCount > 0 && <Chip icon={<Share2 className="h-3 w-3" />} label={`${socialCount} social`} />}
          {!hasAnySignal(s) && !compact && (
            <span className="text-[10px] text-muted-foreground">No structure detected on homepage</span>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * Whether the website intelligence for this lead is still OUTSTANDING. A URL waits for its audit; a lead
 * with no URL waits only until the website search has concluded — after that it has been checked, and
 * calling it "not audited" would say nobody looked.
 */
export function awaitingWebsiteCheck(website: WebsiteSummary): boolean {
  if (website.audited) return false;
  return website.url !== null || !website.checked;
}

function Chip({ icon, label }: { icon?: React.ReactNode; label: string }) {
  return (
    <span className="inline-flex items-center gap-0.5 rounded border border-border-subtle bg-muted/60 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
      {icon}
      {label}
    </span>
  );
}

function hasAnySignal(s: NonNullable<WebsiteSummary["signals"]>): boolean {
  return (
    s.https || s.about || s.contact || s.services || s.careers || s.blog ||
    s.emailDetected || s.phoneDetected || s.facebook || s.instagram || s.linkedin || s.youtube
  );
}
