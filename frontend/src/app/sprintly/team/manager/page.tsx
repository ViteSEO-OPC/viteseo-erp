import { ManagerTeamView } from "@/modules/projects/components/team/ManagerTeamView";
import { RequireManager } from "@/core/auth/RequireRole";

export default function ManagerTeamPage() {
  return (
    <RequireManager>
      <ManagerTeamView />
    </RequireManager>
  );
}
