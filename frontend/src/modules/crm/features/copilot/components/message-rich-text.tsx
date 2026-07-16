"use client";

import { useRouter } from "next/navigation";
import { runAction } from "@/modules/crm/features/copilot/engine/action-engine";
import type { RichTextSegment } from "@/modules/crm/features/copilot/types/copilot-types";

type Props = {
  segments: RichTextSegment[];
};

export function MessageRichText({ segments }: Props) {
  const router = useRouter();

  return (
    <p className="text-sm leading-relaxed text-foreground whitespace-pre-wrap">
      {segments.map((seg, i) => {
        switch (seg.type) {
          case "bold":
            return (
              <strong key={i} className="font-bold text-foreground">
                {seg.text}
              </strong>
            );
          case "emphasis":
            return (
              <strong key={i} className="font-bold text-teal-700">
                {seg.text}
              </strong>
            );
          case "link":
            // Inline navigation link — routes through the SAME action engine (known-route guard
            // + router.push) that intents use, so navigation stays consistent everywhere.
            return (
              <button
                key={i}
                type="button"
                onClick={() =>
                  runAction({ type: "navigate", target: seg.route }, (href) => router.push(href))
                }
                className="font-semibold text-teal-700 underline underline-offset-2 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-sm"
              >
                {seg.label}
              </button>
            );
          default:
            return <span key={i}>{seg.text}</span>;
        }
      })}
    </p>
  );
}
