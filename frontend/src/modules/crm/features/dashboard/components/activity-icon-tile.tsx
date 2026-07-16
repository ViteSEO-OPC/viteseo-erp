import { LogIn, Phone, StickyNote } from "lucide-react";
import type { ComponentProps } from "react";
import type { ActivityType } from "@/modules/crm/features/dashboard/types/dashboard-types";
import { cn } from "@/modules/crm/lib/utils";

// Copilot / AI activity glyph (provided asset: zap.svg). stroke=currentColor so it inherits
// the tile's text color (teal-700) instead of the asset's hardcoded teal — keeps the approved
// tile colour scheme, only the glyph shape changes.
function ZapIcon({ className, ...props }: ComponentProps<"svg">) {
  return (
    <svg viewBox="0 0 18 18" fill="none" className={className} aria-hidden {...props}>
      <path
        d="M2.59928 10.3853C2.71948 10.4607 2.85861 10.5005 3.00052 10.5H8.24993C8.37123 10.4997 8.49082 10.5287 8.59844 10.5847C8.70605 10.6407 8.79848 10.7219 8.86779 10.8215C8.93711 10.9211 8.98124 11.036 8.9964 11.1563C9.01157 11.2767 8.99731 11.399 8.95485 11.5126L7.51501 16.0279C7.49246 16.1099 7.49845 16.1971 7.53201 16.2753C7.56557 16.3535 7.6247 16.4179 7.6997 16.458C7.77469 16.4981 7.8611 16.5116 7.94473 16.4961C8.02836 16.4806 8.10425 16.4372 8.15994 16.3729L15.5841 8.72244C15.6735 8.61222 15.7298 8.47888 15.7465 8.33793C15.7631 8.19697 15.7394 8.05418 15.6782 7.92615C15.6169 7.79812 15.5206 7.6901 15.4004 7.61464C15.2802 7.53918 15.1411 7.49939 14.9992 7.49987H9.74976C9.62846 7.50026 9.50887 7.47122 9.40126 7.41523C9.29364 7.35925 9.20122 7.27799 9.1319 7.17842C9.06259 7.07885 9.01845 6.96396 9.00329 6.84359C8.98813 6.72321 9.00239 6.60096 9.04484 6.48731L10.4847 1.97204C10.5072 1.89002 10.5012 1.80277 10.4677 1.72461C10.4341 1.64644 10.375 1.58201 10.3 1.54189C10.225 1.50177 10.1386 1.48834 10.055 1.50381C9.97133 1.51928 9.89544 1.56273 9.83975 1.62702L2.41559 9.27747C2.32618 9.3877 2.26988 9.52104 2.25323 9.66199C2.23658 9.80295 2.26025 9.94573 2.32151 10.0738C2.38276 10.2018 2.47909 10.3098 2.59928 10.3853Z"
        stroke="currentColor"
        strokeWidth={2}
        strokeLinecap="round"
      />
    </svg>
  );
}

// Email activity glyph (provided asset: mail.svg). Same currentColor treatment — inherits the
// email tile's gray-600 text color.
function MailIcon({ className, ...props }: ComponentProps<"svg">) {
  return (
    <svg viewBox="0 0 18 18" fill="none" className={className} aria-hidden {...props}>
      <path
        d="M16.5002 5.25039L9.75643 9.54521C9.52759 9.6781 9.26765 9.7481 9.003 9.7481C8.73835 9.7481 8.47841 9.6781 8.24956 9.54521L1.49902 5.25039M2.99914 3.00061H15.0001C15.8286 3.00061 16.5002 3.67212 16.5002 4.50046V13.4996C16.5002 14.3279 15.8286 14.9994 15.0001 14.9994H2.99914C2.17065 14.9994 1.49902 14.3279 1.49902 13.4996V4.50046C1.49902 3.67212 2.17065 3.00061 2.99914 3.00061Z"
        stroke="currentColor"
        strokeWidth={2}
        strokeLinecap="round"
      />
    </svg>
  );
}

const iconMap = {
  ai: ZapIcon,
  email: MailIcon,
  call: Phone,
  // Authentication / session events (login, logout) — distinct from AI and email tiles.
  auth: LogIn,
  // Internal note events (created / edited / deleted) on a lead.
  note: StickyNote,
};

const bgMap: Record<ActivityType, string> = {
  ai: "bg-teal-100 text-teal-700",
  email: "bg-teal-100 text-teal-700",
  call: "bg-muted text-secondary-foreground",
  auth: "bg-muted text-secondary-foreground",
  note: "bg-indigo-100 text-indigo-600",
};

type Props = {
  type: ActivityType;
};

export function ActivityIconTile({ type }: Props) {
  const Icon = iconMap[type];

  return (
    <span
      className={cn(
        "flex h-8 w-8 shrink-0 items-center justify-center rounded-lg",
        bgMap[type],
      )}
    >
      <Icon className="h-4 w-4" />
    </span>
  );
}
