"use client";

import { useRouter } from "next/navigation";

import { useAuth } from "@/core/auth-context";
import { AppShell } from "@/core/components/app-shell";
import { useViewMode } from "@/modules/hr/components/view-mode-context";
import { EmployeeAvatar } from "@/modules/hr/ui/employee-avatar";
import { useMyEmployee } from "@/modules/hr/hooks/use-my-employee";

/**
 * TeamOra's frame — the platform-standard `AppShell` plus HR's two portal
 * concerns: the HR ⇄ Employee view switch (policy owned by `useViewMode`,
 * surfaced in the shell's UserMenu) and the viewer's own profile picture.
 *
 * The drawer, the collapse rail and the header all live in `AppShell` now.
 * This file used to carry its own copy of them, plus an HR-only mobile top
 * bar, from before the shared shell was responsive — which is how TeamOra
 * ended up breaking to a drawer at `xl` while every other portal used `lg`.
 */
export function TeamoraShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { variant, canSwitch, toggle } = useViewMode();

  // The UserMenu shows the viewer's own profile picture. AppShell takes the
  // rendered avatar rather than a URL — where a face comes from is ours to
  // know. Only passed when there is a photo, so accounts without one keep the
  // plain initials chip. The upload already invalidates this query, so a new
  // picture lands here without a reload.
  const { user, employee } = useAuth();
  const { data: me } = useMyEmployee({ enabled: !!employee });
  const avatar = me?.photo_url ? (
    <EmployeeAvatar
      name={me.name ?? user?.name ?? "?"}
      photoUrl={me.photo_url}
      colorSeed={me.id}
      className="size-9"
    />
  ) : undefined;

  // Always land on `/teamora` when switching views — the one route both navs
  // share — since the page you were on (say `/teamora/attendance`) may not
  // exist in the portal you are switching into.
  const switchView = () => {
    toggle();
    router.push("/teamora");
  };

  return (
    <AppShell
      variant={variant}
      canSwitchView={canSwitch}
      onSwitchView={switchView}
      avatar={avatar}
    >
      {children}
    </AppShell>
  );
}
