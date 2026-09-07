"use client";

import { Badge } from "@/modules/crm/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";
import {
  ACTION_LABEL,
  type BusinessRuleAudit,
} from "@/modules/crm/features/business-rules/types/business-rules-types";

function fmt(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString() : "—";
}

/** Compact a stored value (which may be JSON for multi-field/version actions) for display. */
function readable(value: string | null): string {
  if (!value) return "—";
  try {
    const parsed = JSON.parse(value);
    if (parsed && typeof parsed === "object") {
      return Object.entries(parsed)
        .map(([k, v]) => `${k}: ${v}`)
        .join(", ");
    }
  } catch {
    /* not JSON — show as-is */
  }
  return value;
}

/** Read-only, append-only audit trail. Nothing here is editable — it is a permanent record. */
export function AuditHistory({ entries }: { entries: BusinessRuleAudit[] }) {
  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-40">When</TableHead>
            <TableHead className="w-28">Action</TableHead>
            <TableHead>Rule</TableHead>
            <TableHead>Change</TableHead>
            <TableHead className="w-16">Ver.</TableHead>
            <TableHead className="w-36">By</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {entries.map((e) => (
            <TableRow key={e.id}>
              <TableCell className="text-sm text-muted-foreground">{fmt(e.createdAt)}</TableCell>
              <TableCell>
                <Badge variant="outline">{ACTION_LABEL[e.action]}</Badge>
              </TableCell>
              <TableCell className="font-mono text-[11px]">{e.ruleKey ?? "—"}</TableCell>
              <TableCell className="text-sm">
                {e.previousValue || e.newValue ? (
                  <span className="text-muted-foreground">
                    {readable(e.previousValue)} <span className="text-foreground">→</span>{" "}
                    {readable(e.newValue)}
                  </span>
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell className="font-mono tabular-nums">v{e.versionNumber}</TableCell>
              <TableCell className="text-sm text-muted-foreground">{e.user?.name ?? "System"}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
