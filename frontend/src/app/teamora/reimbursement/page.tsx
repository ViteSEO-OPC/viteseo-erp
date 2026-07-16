import { redirect } from "next/navigation";

// Moved under the Finance group in the Phase-2 nav (§14.2).
export default function ReimbursementRedirect() {
  redirect("/teamora/finance/reimbursement");
}
