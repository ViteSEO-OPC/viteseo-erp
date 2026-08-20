import { MemberTeamView } from "@/modules/projects/components/team/member-team-view";
import { RequireMember } from "@/core/auth/RequireRole";

export default function MemberTeamPage() {
  return (
    <RequireMember>
      <MemberTeamView />
    </RequireMember>
  );
}
