import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Allow the dev server (HMR websocket + /_next assets) to be reached over the LAN,
  // not just localhost — Next 16 blocks cross-origin dev requests by default. Add your
  // machine's LAN IP here; the subnet glob keeps it working across DHCP address changes.
  allowedDevOrigins: ["192.168.68.91", "192.168.68.*"],
};

export default nextConfig;
