import { Trash2 } from "lucide-react";
import { Button } from "@/modules/crm/components/ui/button";

type Props = {
  onDelete?: () => void;
};

export function DangerConfirmRegion({ onDelete }: Props) {
  return (
    <div className="flex flex-col items-start gap-2 pt-4">
      <p className="text-xs text-muted-foreground">
        Deleting a lead is permanent and can&apos;t be undone.
      </p>
      <Button
        variant="outline"
        size="sm"
        onClick={onDelete}
        className="border-red-500/40 text-red-500 hover:bg-red-500/10"
      >
        <Trash2 className="h-4 w-4" />
        Delete Lead
      </Button>
    </div>
  );
}
