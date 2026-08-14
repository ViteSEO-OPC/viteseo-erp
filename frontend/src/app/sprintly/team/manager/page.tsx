import { ManagerTeamView } from "@/modules/projects/components/team/manager-team-view";
import { RequireManager } from "@/core/auth/RequireRole";

export default function ManagerTeamPage() {
  return (
    <RequireManager>
      <ManagerTeamView />
    </RequireManager>
  );
}
