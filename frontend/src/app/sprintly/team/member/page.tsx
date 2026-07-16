import { MemberTeamView } from "@/modules/projects/components/team/MemberTeamView";
import { RequireMember } from "@/core/auth/RequireRole";

export default function MemberTeamPage() {
  return (
    <RequireMember>
      <MemberTeamView />
    </RequireMember>
  );
}
