import { ManagerTeamView } from "@/modules/projects/components/ManagerTeamView";
import { RequireManager } from "@/core/auth/RequireRole";

export default function ManagerTeamPage() {
  return (
    <RequireManager>
      <ManagerTeamView />
    </RequireManager>
  );
}
