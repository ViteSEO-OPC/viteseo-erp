import { ProjectDetailView } from "@/modules/projects/components/ProjectDetailView";

export default async function ProjectDetailPage({
  params,
}: {
  params: Promise<{ projectId: string }>;
}) {
  const { projectId } = await params;
  return <ProjectDetailView projectId={projectId} />;
}
