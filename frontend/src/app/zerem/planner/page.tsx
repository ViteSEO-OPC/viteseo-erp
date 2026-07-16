import { CalendarLayout } from "@/modules/crm/features/calendar/components/calendar-layout";

// Planner — the official enterprise scheduling module (personal BD workspace). Primary route.
// The underlying Calendar feature components are reused internally; the rename is user-facing +
// routing. `/calendar` remains as a temporary redirect here (see ../calendar/page.tsx).
export default function PlannerPage() {
  return <CalendarLayout />;
}
