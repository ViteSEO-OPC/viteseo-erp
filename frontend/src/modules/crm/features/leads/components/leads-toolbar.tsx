"use client";

import { Plus, Upload, Download, Loader2 } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";

type Props = {
  onImport?: () => void;
  onCreate?: () => void;
  onExport?: () => void;
  exporting?: boolean;
};

export function LeadsToolbar({ onImport, onCreate, onExport, exporting }: Props) {
  const { can } = usePermissions();

  return (
    <div className="flex items-center gap-2">
      {can("leads.import") ? (
        <Button variant="outline" onClick={onImport}>
          Import
          <Upload className="h-4 w-4" />
        </Button>
      ) : null}
      <Button variant="outline" onClick={onExport} disabled={exporting}>
        Export
        {exporting ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <Download className="h-4 w-4" />
        )}
      </Button>
      <Button variant="default" onClick={onCreate}>
        <Plus className="h-4 w-4" />
        Add New Lead
      </Button>
    </div>
  );
}
