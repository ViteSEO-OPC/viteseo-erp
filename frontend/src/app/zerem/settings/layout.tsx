import type { ReactNode } from "react";
import { SettingsLayout } from "@/modules/crm/features/settings/components/settings-layout";

export default function SettingsRouteLayout({ children }: { children: ReactNode }) {
  return <SettingsLayout>{children}</SettingsLayout>;
}
