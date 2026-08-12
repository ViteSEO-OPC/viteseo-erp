"use client";

import {
  useProjectNotifications,
} from "@/modules/projects/hooks/use-project-notifications";

/** Renders nothing — mounts the live-notifications listener for a section's lifetime. */
export function ProjectNotificationListener(): null {
  useProjectNotifications();
  return null;
}
