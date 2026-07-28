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
    // `ml-auto` keeps the action group right-aligned even when the row wraps: `justify-between` on
    // the parent aligns a lone wrapped item to the start, which left-aligned these buttons at ~1024px
    // (sidebar still expanded, viewport not yet wide enough). No effect when both fit on one row.
    <div className="ml-auto flex items-center gap-2">
      {/* `can()` matches the permission name exactly — the granted names are `crm.`-prefixed
          (crm.leads.import), so the unprefixed form was never true and hid Import from BOTH
          Admin/Super Admin and BD. Both roles hold crm.leads.import, so this restores it for both
          without changing any permission. */}
      {can("crm.leads.import") ? (
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
