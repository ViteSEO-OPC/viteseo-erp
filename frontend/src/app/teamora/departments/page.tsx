import { redirect } from "next/navigation";

// Moved under the Company group in the Phase-2 nav (§14.2).
export default function DepartmentsRedirect() {
  redirect("/teamora/company/departments");
}
