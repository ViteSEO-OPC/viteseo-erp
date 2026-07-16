import { MemberTeamView } from "@/modules/projects/components/MemberTeamView";
import { RequireMember } from "@/core/auth/RequireRole";

export default function MemberTeamPage() {
  return (
    <RequireMember>
      <MemberTeamView />
    </RequireMember>
  );
}
