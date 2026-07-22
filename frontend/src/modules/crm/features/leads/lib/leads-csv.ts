import type { Lead } from "@/modules/crm/features/leads/types/lead-types";

// Exported columns — the meaningful lead fields (mirrors the table's data columns, minus UI-only
// affordances like row selection/actions). Order here is the CSV column order.
const COLUMNS: { header: string; value: (lead: Lead) => string | number | null }[] = [
  { header: "Business", value: (l) => l.business },
  { header: "Contact", value: (l) => l.contact },
  { header: "Email", value: (l) => l.email },
  { header: "Phone", value: (l) => l.phone },
  { header: "Industry", value: (l) => l.industry },
  { header: "Acquisition Channel", value: (l) => l.acquisitionChannel },
  { header: "Website Status", value: (l) => l.websiteStatus },
  { header: "Priority", value: (l) => l.priority },
  { header: "Status", value: (l) => l.updateStatus },
  { header: "Score", value: (l) => l.score },
  { header: "Last Activity", value: (l) => l.lastActivity },
];

function escapeCsv(value: string | number | null): string {
  const s = value === null || value === undefined ? "" : String(value);
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export function buildLeadsCsv(leads: Lead[]): string {
  const header = COLUMNS.map((c) => c.header).join(",");
  const rows = leads.map((lead) => COLUMNS.map((c) => escapeCsv(c.value(lead))).join(","));

  return [header, ...rows].join("\r\n");
}

/** Build the CSV and trigger a browser download. `﻿` BOM so Excel reads UTF-8 correctly. */
export function downloadLeadsCsv(leads: Lead[], filename: string): void {
  const blob = new Blob([`﻿${buildLeadsCsv(leads)}`], {
    type: "text/csv;charset=utf-8;",
  });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
}
