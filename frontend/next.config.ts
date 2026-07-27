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
      // The HR approvals page covers leave + half-day + early-out + overtime, so it is no longer
      // "leave" — renamed to match the employee-side Work Schedule page (OT-5 follow-up).
      { source: "/teamora/requests/leave", destination: "/teamora/requests/work-schedule", permanent: false },
    ];
  },
};

export default nextConfig;
