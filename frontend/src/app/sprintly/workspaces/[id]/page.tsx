import { RequireManager } from '@/core/auth/RequireRole';
import { WorkspaceDetailView } from '@/modules/projects/components/workspace-detail-view';

export default async function WorkspaceDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return (
    <RequireManager redirectTo="/sprintly/403">
      <WorkspaceDetailView workspaceId={id} />
    </RequireManager>
  );
}
