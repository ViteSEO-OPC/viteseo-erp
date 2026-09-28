"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

import { getModuleTiles, type ModuleKey } from "@/core/access";
import { useAuth } from "@/core/auth-context";
import { useFeatures } from "@/core/use-features";

/**
 * Keep someone inside a system only while they may use it.
 *
 * The launcher already locks the card of a system a person may not enter. This is
 * the same rule (getModuleTiles) applied to the system itself: a bookmarked URL does
 * not get round it, and -- the reason it exists -- when an access change arrives
 * (AccessChanged, or a tab coming back to the front) and the re-read roles no longer
 * open this system, the person is walked back to the launcher instead of being left
 * on a page that now answers 403 to everything.
 *
 * Mount inside <RequireAuth>, which has already settled who the user is. While the
 * org's entitlements are loading, getModuleTiles judges on permissions alone, so an
 * entitled user never sees a false bounce.
 */
export function RequireModule({
  module,
  children,
}: {
  module: ModuleKey;
  children: React.ReactNode;
}) {
  const { user, roles, permissions } = useAuth();
  const { data: features } = useFeatures();
  const router = useRouter();

  const allowed =
    getModuleTiles(roles as unknown as string[], permissions, features?.modules).find(
      (tile) => tile.key === module,
    )?.accessible ?? false;

  useEffect(() => {
    if (user && !allowed) router.replace("/");
  }, [user, allowed, router]);

  if (!allowed) {
    return (
      <div className="flex min-h-screen items-center justify-center text-sm text-muted-foreground">
        Loading…
      </div>
    );
  }

  return <>{children}</>;
}
