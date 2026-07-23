export const queryKeys = {
  dashboard: {
    all: ["dashboard"] as const,
    kpis: () => [...queryKeys.dashboard.all, "kpis"] as const,
    copilotSummary: () =>
      [...queryKeys.dashboard.all, "copilot-summary"] as const,
    // `filters` keeps the Leads workspace's filtered buckets cached separately from the Dashboard's
    // unfiltered ones. The Dashboard passes nothing, so its key is unchanged.
    priorityBuckets: (filters: Record<string, string> = {}) =>
      [...queryKeys.dashboard.all, "priority-buckets", filters] as const,
    recentLeads: () =>
      [...queryKeys.dashboard.all, "recent-leads"] as const,
    recentActivity: () =>
      [...queryKeys.dashboard.all, "recent-activity"] as const,
    bdPerformance: () =>
      [...queryKeys.dashboard.all, "bd-performance"] as const,
    leadAcquisition: () =>
      [...queryKeys.dashboard.all, "lead-acquisition"] as const,
    // BD KPI Engine operational catalog (M-01…M-06) — GET /dashboard/kpi-metrics.
    kpiMetrics: () => [...queryKeys.dashboard.all, "kpi-metrics"] as const,
  },
  leads: {
    all: ["leads"] as const,
    lists: () => [...queryKeys.leads.all, "list"] as const,
    list: (filters: Record<string, unknown>) =>
      [...queryKeys.leads.lists(), filters] as const,
    // Priority Summary cards (GET /leads/priority-summary). Nested UNDER lists() so every mutation
    // that already invalidates leads.lists() (create/update/delete/bulk/import/restore) also
    // refreshes the cards by prefix match — the cards can never drift from the table.
    prioritySummary: (filters: Record<string, unknown>) =>
      [...queryKeys.leads.lists(), "priority-summary", filters] as const,
    details: () => [...queryKeys.leads.all, "detail"] as const,
    detail: (id: string) => [...queryKeys.leads.details(), id] as const,
    // Per-lead activity timeline. Nested under detail(id) so the existing
    // update/assign invalidations of leads.detail(id) also refresh it (prefix match).
    activities: (id: string) => [...queryKeys.leads.detail(id), "activities"] as const,
    // Per-lead internal notes (GET /leads/{lead}/notes). Nested under detail(id), like
    // activities, so note writes invalidate only this lead's data — never unrelated feeds.
    notes: (id: string) => [...queryKeys.leads.detail(id), "notes"] as const,
    // Per-lead email conversation (GET /leads/{lead}/emails). Nested under detail(id) so future
    // email writes (e.g. an inbound reply landing) invalidate only this lead's thread by prefix.
    emails: (id: string) => [...queryKeys.leads.detail(id), "emails"] as const,
    // Per-lead rendered email templates (GET /leads/{lead}/email-templates) — Purpose+Tone → Subject+Body
    // rendered for this lead. Nested under detail(id).
    emailTemplates: (id: string) => [...queryKeys.leads.detail(id), "email-templates"] as const,
    // Per-lead call attempts (GET /leads/{lead}/calls) — the Calling Workspace Call History AND the
    // active-call session. Nested under detail(id), like activities/notes, so lead-detail invalidations
    // cascade and call writes touch only this lead's data by prefix.
    calls: (id: string) => [...queryKeys.leads.detail(id), "calls"] as const,
  },
  niches: {
    // Business Niche registry (GET /niches) — the vocabulary behind the shared Business Niche
    // selector. ONE list key shared by every selector instance, so creating a niche from the
    // Create Lead wizard also refreshes the one open in the Edit Lead drawer.
    all: ["niches"] as const,
    list: () => [...queryKeys.niches.all, "list"] as const,
  },
  followUps: {
    all: ["follow-ups"] as const,
    lists: () => [...queryKeys.followUps.all, "list"] as const,
    list: (filters: Record<string, unknown>) =>
      [...queryKeys.followUps.lists(), filters] as const,
  },
  activityLogs: {
    all: ["activity-logs"] as const,
    lists: () => [...queryKeys.activityLogs.all, "list"] as const,
    list: (filters: Record<string, unknown>) =>
      [...queryKeys.activityLogs.lists(), filters] as const,
  },
  serviceAreas: {
    all: ["service-areas"] as const,
    // Nearby Clients — target-area registry + leads-in-target summary.
    list: () => [...queryKeys.serviceAreas.all, "list"] as const,
  },
  nearbyClients: {
    all: ["nearby-clients"] as const,
    // Discovered-business prospect pool (GET /nearby-clients).
    list: () => [...queryKeys.nearbyClients.all, "list"] as const,
  },
  performance: {
    all: ["performance"] as const,
    // "My Performance" personal KPI summary (GET /dashboard/performance-summary).
    summary: () => [...queryKeys.performance.all, "summary"] as const,
  },
  reports: {
    all: ["reports"] as const,
    kpis: () => [...queryKeys.reports.all, "kpis"] as const,
    statusBars: () => [...queryKeys.reports.all, "status-bars"] as const,
    priorityDonut: () => [...queryKeys.reports.all, "priority-donut"] as const,
    outreachLine: () => [...queryKeys.reports.all, "outreach-line"] as const,
    conversionBars: () => [...queryKeys.reports.all, "conversion-bars"] as const,
    industryBars: () => [...queryKeys.reports.all, "industry-bars"] as const,
  },
  planner: {
    all: ["planner"] as const,
    // ONE shared list key for the whole module — every Planner view (Month/Week/Day/Year/Agenda +
    // Board) reads this single cache entry, so all views are presentations of the same dataset and
    // a mutation invalidating it refreshes every view at once (cross-view synchronization).
    list: () => [...queryKeys.planner.all, "list"] as const,
    events: (dateRange: { start: string; end: string }) =>
      [...queryKeys.planner.all, "events", dateRange] as const,
  },
  me: {
    // The authenticated user's own profile (GET /me) — powers the BD self-service Settings page.
    all: ["me"] as const,
    profile: () => [...queryKeys.me.all, "profile"] as const,
  },
  users: {
    all: ["users"] as const,
    lists: () => [...queryKeys.users.all, "list"] as const,
    list: (filters: Record<string, unknown>) =>
      [...queryKeys.users.lists(), filters] as const,
    details: () => [...queryKeys.users.all, "detail"] as const,
    detail: (id: string) => [...queryKeys.users.details(), id] as const,
  },
  archive: {
    // Archive Management — soft-deleted ("archived") leads (GET /leads/archived).
    all: ["archive"] as const,
    lists: () => [...queryKeys.archive.all, "list"] as const,
    list: (filters: Record<string, unknown>) =>
      [...queryKeys.archive.lists(), filters] as const,
  },
  notifications: {
    // In-app Notification Center — reads the existing Laravel Database Notifications
    // (`notifications` table). `all` prefix lets a single mark-read/mark-all invalidation
    // refresh both the list and the unread-count badge at once.
    all: ["notifications"] as const,
    list: () => [...queryKeys.notifications.all, "list"] as const,
    unreadCount: () => [...queryKeys.notifications.all, "unread-count"] as const,
  },
} as const;
