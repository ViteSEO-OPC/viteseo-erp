import { redirect } from "next/navigation";

// Backward-compatibility redirect: the module was renamed Calendar → Planner. `/calendar` is a
// temporary alias that forwards to the primary `/planner` route. Remove once no bookmarks/links
// reference `/calendar`. All new navigation and route references use `/planner`.
export default function CalendarRedirectPage() {
  redirect("/zerem/planner");
}
