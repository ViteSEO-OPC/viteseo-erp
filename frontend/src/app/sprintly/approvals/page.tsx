import { RequireManager } from "@/core/auth/RequireRole";
import { ApprovalsView } from "@/modules/projects/components/approvals/ApprovalsView";

export default function ApprovalsPage() {
  return (
    <RequireManager redirectTo="/sprintly/403">
      <ApprovalsView />
    </RequireManager>
  );
}
