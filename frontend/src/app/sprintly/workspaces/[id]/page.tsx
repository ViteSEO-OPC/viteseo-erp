import { WorkspaceDetailView } from '@/modules/projects/components/workspaces/WorkspaceDetailView';

export default async function WorkspaceDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <WorkspaceDetailView workspaceId={id} />;
}
