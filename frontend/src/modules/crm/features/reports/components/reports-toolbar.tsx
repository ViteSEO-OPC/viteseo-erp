"use client";

import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
} from "@/modules/crm/components/ui/select";
import { Button } from "@/modules/crm/components/ui/button";
import { Download } from "lucide-react";

type ReportsToolbarProps = {
  range: string;
  onRangeChange: (range: string) => void;
};

export function ReportsToolbar({ range, onRangeChange }: ReportsToolbarProps) {
  return (
    <div className="flex items-center justify-between gap-4">
      <Select
        value={range}
        onValueChange={(v) => onRangeChange(v ?? "Last 30 Days")}
      >
        <SelectTrigger className="h-8 rounded-lg bg-gray-150 px-3 py-1 text-sm">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="Last 30 Days">Last 30 Days</SelectItem>
          <SelectItem value="Last 90 Days">Last 90 Days</SelectItem>
          <SelectItem value="This Year">This Year</SelectItem>
        </SelectContent>
      </Select>

      <Button variant="default" size="sm">
        <Download className="h-4 w-4" />
        Export
      </Button>
    </div>
  );
}
