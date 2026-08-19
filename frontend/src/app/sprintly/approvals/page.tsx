import { RequireManager } from "@/core/auth/RequireRole";
import { ApprovalsView } from "@/modules/projects/components/review/approvals-view";

export default function ApprovalsPage() {
  return (
    <RequireManager redirectTo="/sprintly/403">
      <ApprovalsView />
    </RequireManager>
  );
}
