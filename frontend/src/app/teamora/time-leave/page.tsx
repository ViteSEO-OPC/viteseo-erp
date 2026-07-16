import { redirect } from "next/navigation";

// Split into the Request group in the Phase-2 nav (§14.2); leave queue moved here.
export default function TimeLeaveRedirect() {
  redirect("/teamora/requests/leave");
}
