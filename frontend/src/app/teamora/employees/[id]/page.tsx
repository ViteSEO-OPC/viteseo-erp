import { redirect } from "next/navigation";

// Moved under the People group in the Phase-2 nav (§14.2).
export default async function EmployeeRecordRedirect({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  redirect(`/teamora/people/employees/${id}`);
}
