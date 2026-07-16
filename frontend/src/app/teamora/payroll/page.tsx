import { redirect } from "next/navigation";

// Moved under the Finance group in the Phase-2 nav (§14.2).
export default function PayrollRedirect() {
  redirect("/teamora/finance/payroll");
}
