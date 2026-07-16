import type { ReactNode } from "react";
import { SettingsNav } from "@/modules/crm/features/settings/components/settings-nav";

/** Settings shell: left section sub-nav + the active section's content. */
export function SettingsLayout({ children }: { children: ReactNode }) {
  return (
    <div className="mx-auto flex w-full max-w-8xl flex-col gap-6 md:flex-row md:items-start">
      <SettingsNav />
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}
