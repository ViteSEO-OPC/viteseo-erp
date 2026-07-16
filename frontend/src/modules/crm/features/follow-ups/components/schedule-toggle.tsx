"use client";

import { Switch } from "@/modules/crm/components/ui/switch";

type ScheduleToggleProps = {
  checked: boolean;
  onChange: () => void;
};

export function ScheduleToggle({ checked, onChange }: ScheduleToggleProps) {
  return (
    <label
      className="flex cursor-pointer items-center gap-2 text-sm whitespace-nowrap text-muted-foreground"
      role="switch"
      aria-checked={checked}
    >
      <span>Schedule for later</span>
      <Switch checked={checked} onCheckedChange={onChange} />
    </label>
  );
}
