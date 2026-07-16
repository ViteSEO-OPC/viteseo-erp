import { redirect } from "next/navigation";

// Moved under the People group in the Phase-2 nav (§14.2).
export default function EmployeesRedirect() {
  redirect("/teamora/people/employees");
}
