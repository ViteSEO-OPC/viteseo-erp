"use client";

import { useState } from "react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogClose,
} from "@/components/ui/dialog";
import { SimpleSelect } from "@/modules/hr/ui/select";
import { DatePicker } from "@/modules/hr/ui/date-picker";
import { useAddHoliday, useUpdateHoliday } from "@/modules/hr/hooks/use-holidays";
import { HOLIDAY_TYPES, type Holiday } from "@/modules/hr/types/holiday";

export function HolidayFormDialog({
  open,
  onOpenChange,
  setId,
  holiday,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  setId: number;
  holiday?: Holiday | null;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] max-w-[calc(100%-3rem)] overflow-y-auto sm:max-w-lg">
        {open && <Body key={holiday?.id ?? "new"} setId={setId} holiday={holiday ?? null} onDone={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  );
}

function Body({ setId, holiday, onDone }: { setId: number; holiday: Holiday | null; onDone: () => void }) {
  const isEdit = !!holiday;
  const addMut = useAddHoliday(setId);
  const updateMut = useUpdateHoliday(setId);

  const [name, setName] = useState(holiday?.name ?? "");
  const [date, setDate] = useState(holiday?.date ?? "");
  const [type, setType] = useState(holiday?.type ?? ""); // "" = unclassified, HR must pick
  const pending = addMut.isPending || updateMut.isPending;

  async function submit() {
    if (!name.trim() || !date || !type) return;
    const input = { name: name.trim(), date, type };
    try {
      if (holiday) {
        await updateMut.mutateAsync({ id: holiday.id, input });
        toast.success("Holiday updated");
      } else {
        await addMut.mutateAsync(input);
        toast.success("Holiday added");
      }
      onDone();
    } catch {
      toast.error("Could not save the holiday.");
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{isEdit ? "Edit holiday" : "Add holiday"}</DialogTitle>
        <DialogDescription>Only Regular and Special (Non-Working) days affect leave/pay; Special Working is a normal work day.</DialogDescription>
      </DialogHeader>

      <div className="space-y-3">
        <div className="space-y-1.5">
          <Label htmlFor="h-name">Name</Label>
          <Input id="h-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Independence Day" />
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div className="space-y-1.5">
            <Label htmlFor="h-date">Date</Label>
            <DatePicker id="h-date" value={date} onChange={setDate} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="h-type">Type</Label>
            <SimpleSelect
              id="h-type"
              value={type}
              onValueChange={setType}
              placeholder="— Select type —"
              options={HOLIDAY_TYPES.map((t) => ({ value: t.value, label: t.label }))}
            />
          </div>
        </div>
      </div>

      <DialogFooter>
        <DialogClose asChild>
          <Button variant="outline">Cancel</Button>
        </DialogClose>
        <Button disabled={pending || !name.trim() || !date || !type} onClick={submit}>
          {pending ? "Saving…" : isEdit ? "Save changes" : "Add holiday"}
        </Button>
      </DialogFooter>
    </>
  );
}
