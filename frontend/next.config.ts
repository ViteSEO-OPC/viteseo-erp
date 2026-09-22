import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Allow the dev server (HMR websocket + /_next assets) to be reached over the LAN,
  // not just localhost — Next 16 blocks cross-origin dev requests by default. Add your
  // machine's LAN IP here; the subnet glob keeps it working across DHCP address changes.
  allowedDevOrigins: ["192.168.68.91", "192.168.68.*"],

  // Time Off became Work Schedule when overtime joined it (OT-4) — keep old links/bookmarks alive.
  async redirects() {
    return [
      { source: "/teamora/time-off", destination: "/teamora/work-schedule", permanent: false },
      // Same alias, different old name — and this one is still being HANDED OUT: employee
      // notifications for "Leave approved/rejected" and "Half-day approved/rejected" carry it
      // (NotificationService::MY_TIME_LEAVE). It has to redirect here, not in a page, because
      // the /teamora layout only opens EMPLOYEE_PATHS to non-HR users; an alias rendered inside
      // that layout is read as HR-management and bounces the employee to "/".
      { source: "/teamora/time-leave", destination: "/teamora/work-schedule", permanent: false },
      // The HR approvals page covers leave + half-day + early-out + overtime, so it is no longer
      // "leave" — renamed to match the employee-side Work Schedule page (OT-5 follow-up).
      { source: "/teamora/requests/leave", destination: "/teamora/requests/work-schedule", permanent: false },
      // Zerem's Team Accounts and Roles & Access became one Team & Roles page, the
      // same single entry TeamOra and Sprintly have. Both old paths are bookmarked
      // and both are named in the in-app Copilot's answers, so neither may 404.
      { source: "/zerem/settings/team-accounts", destination: "/zerem/settings/team", permanent: false },
      { source: "/zerem/settings/rules-permissions", destination: "/zerem/settings/team", permanent: false },
    ];
  },
};

export default nextConfig;
