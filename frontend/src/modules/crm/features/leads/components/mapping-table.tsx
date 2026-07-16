import { Check } from "lucide-react";
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
  return (
    <div className="overflow-hidden rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border-subtle bg-gray-50">
            <TableHead className="h-9 px-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
              CSV COLUMN
            </TableHead>
            <TableHead className="h-9 px-3 text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
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
              <TableCell className="px-3 py-2.5 text-sm text-foreground">
                {row.csvColumn}
              </TableCell>
              <TableCell className="px-3 py-2.5 text-sm">
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
