import { ArrowRight, Check } from "lucide-react";
import { cn } from "@/modules/crm/lib/utils";
import { useTableDensity } from "@/modules/crm/components/ui/table-density";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/modules/crm/components/ui/table";

type MappingRow = {
  csvColumn: string;
  crmField: string;
};

type Props = {
  rows: MappingRow[];
};

export function MappingTable({ rows }: Props) {
  const density = useTableDensity();
  const th = cn(density.head.condensed, "px-4 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground");
  const td = cn(density.row.condensed, "px-4");

  return (
    <div className="overflow-x-auto rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border-subtle bg-surface-table-header">
            <TableHead className={th}>
              CSV COLUMN
            </TableHead>
            {/* Narrow spacer column carrying the direction arrow, so the two sides read as
                "source → destination" instead of two unrelated lists. */}
            <TableHead className="h-9 w-8 px-0" aria-hidden />
            <TableHead className={th}>
              CRM FIELD
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row, i) => (
            <TableRow
              key={i}
              className="border-b border-border-subtle last:border-b-0"
            >
              {/* Source column: muted + normal weight so the eye lands on the CRM field it maps to. */}
              <TableCell className={cn(td, "text-sm text-muted-foreground")}>
                {row.csvColumn}
              </TableCell>
              <TableCell className={cn(density.row.condensed, "w-8 px-0 text-center align-middle")}>
                <ArrowRight className="inline h-3.5 w-3.5 text-muted-foreground/60" aria-hidden />
              </TableCell>
              <TableCell className={cn(td, "text-sm")}>
                <span className="inline-flex items-center gap-1.5 font-medium text-teal-700">
                  <Check className="h-3.5 w-3.5 text-[var(--color-success-emerald)]" />
                  {row.crmField}
                </span>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
