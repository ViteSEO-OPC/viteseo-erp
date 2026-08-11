import { MemberTeamView } from "@/modules/projects/components/member-team-view";
import { RequireMember } from "@/core/auth/RequireRole";

export default function MemberTeamPage() {
  return (
    <RequireMember>
      <MemberTeamView />
    </RequireMember>
  );
}
