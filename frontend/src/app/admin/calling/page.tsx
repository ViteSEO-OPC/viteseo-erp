"use client";

import Link from "next/link";
import { AlertTriangle, ArrowLeft, CheckCircle2, Lock, PhoneCall } from "lucide-react";

import { RequireAuth, useAuth } from "@/core/auth-context";
import { Badge, Card, StatCard } from "@/core/ui";
import {
  useCallingOverview,
  type CallingMode,
  type CallingOrganization,
} from "@/modules/admin/calling-overview";
import {
  fieldLabel,
  testStatusLabel,
} from "@/modules/crm/features/settings/lib/calling-settings";

/**
 * Admin → Calling: how calling is configured across every organization.
 *
 * ── Why this page exists ──
 * Zerem has no organization switcher, and Settings → Integrations → Calling resolves the organization
 * from the signed-in user's own membership. So the one question support actually gets — "is this
 * client's calling set up, and if not, what is missing?" — had no answer short of a database session.
 *
 * ── READ-ONLY, deliberately ──
 * Nothing here edits anything, and there is no endpoint behind it that could. A client's Twilio account
 * is their property and their bill; being able to SEE a configuration is not a reason to be able to
 * change it. Every change stays with that organization's own admin, on its own settings screen.
 *
 * ── Nothing on this page is a credential ──
 * The API sends verdicts only — no Auth Token, no API Secret, and deliberately not the Account SID or
 * API Key SID either. An organization's own card shows those freely; a cross-tenant screen is where
 * that stops being a good enough reason.
 *
 * ── The deployment banner comes first ──
 * When per-organization mode is on but names no real central organization, every row below says "manual
 * calling" as a CONSEQUENCE of that one setting. Reading the rows first would send an operator hunting
 * for six separate faults that are all the same fault.
 *
 * The reasons a row gives are written in the same words the client's own Calling card uses, so a
 * support answer read from here matches what the client is looking at while being told it.
 */

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <h2 className="mb-3 text-caption font-semibold uppercase tracking-wide text-muted-foreground">
      {children}
    </h2>
  );
}

/** What this organization's BDs actually get on the next call. */
function CallingBadge({ org }: { org: CallingOrganization }) {
  if (org.browserCalling) {
    return (
      <Badge tone="success">
        <CheckCircle2 className="mr-1 inline size-3 -translate-y-px" />
        Browser calling
      </Badge>
    );
  }

  // A configuration that exists and is not running needs attention; one that was never started is the
  // supported default and needs none.
  if (!org.centrallyManaged && (org.configured || org.enabled)) {
    return (
      <Badge tone="warning">
        <AlertTriangle className="mr-1 inline size-3 -translate-y-px" />
        Not running
      </Badge>
    );
  }

  return <Badge tone="neutral">Manual calling</Badge>;
}

/** Why an organization is not on browser calling, in its own Calling card's terms. */
function reason(org: CallingOrganization): string {
  if (org.browserCalling) {
    return org.centrallyManaged
      ? "Calls run on ViteSEO's centralized Twilio account."
      : "Calls run on this organization's own Twilio account.";
  }

  if (org.centrallyManaged) {
    return "Managed by ViteSEO. Browser calling starts once the centralized Twilio account is configured on the server.";
  }

  if (!org.configured && org.source === "none") {
    return "No Twilio account of its own. Its team calls from their phones and logs the outcome in the CRM.";
  }

  if (!org.complete) {
    return `Still needed: ${org.missing.map(fieldLabel).join(", ")}.`;
  }

  if (!org.enabled) {
    return "Complete and verified, but browser calling is switched off in its own settings.";
  }

  return org.lastTestStatus && org.lastTestStatus !== "not_configured"
    ? `Last connection test: ${testStatusLabel(org.lastTestStatus)}.`
    : "Never connection-tested. Its admin runs Test connection on its own Calling card.";
}

function OrgRow({ org }: { org: CallingOrganization }) {
  return (
    <div className="flex items-start gap-5 p-5">
      <span className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-subtle text-dark-teal">
        {org.centrallyManaged ? <Lock className="size-5" /> : <PhoneCall className="size-5" />}
      </span>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="truncate text-body font-semibold text-foreground">{org.name}</h3>
          <span className="text-caption text-muted-foreground/70">#{org.id}</span>
          <CallingBadge org={org} />
          {org.centrallyManaged ? <Badge tone="info">Managed by ViteSEO</Badge> : null}
        </div>

        <p className="mt-1 max-w-prose text-caption leading-relaxed text-muted-foreground">
          {reason(org)}
        </p>

        {/* A stored row nothing reads is exactly what makes a support call confusing. */}
        {org.storedRowIgnored ? (
          <p className="mt-1 text-caption text-muted-foreground/80">
            <AlertTriangle className="mr-1 inline size-3 -translate-y-px" />
            It has a stored Twilio configuration, and nothing reads it while its calling is managed
            centrally.
          </p>
        ) : null}

        {org.lastTestedAt ? (
          <p className="mt-1 text-[11px] text-muted-foreground/70">
            Last connection test {new Date(org.lastTestedAt).toLocaleString()}
          </p>
        ) : null}
      </div>
    </div>
  );
}

/** The deployment-level answer, stated before any row — see the file docblock. */
function DeploymentBanner({ mode }: { mode: CallingMode }) {
  if (mode.misconfiguration) {
    return (
      <Card className="mt-8 border-danger/40 bg-danger-soft/40 p-5">
        <p className="flex items-start gap-2 text-body font-semibold text-danger">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          {mode.misconfigurationMessage}
        </p>
        <p className="mt-2 max-w-prose text-caption leading-relaxed text-muted-foreground">
          No organization is carried on the centralized ViteSEO Twilio account, so every organization
          below — ViteSEO&apos;s own included — is resolved against its own stored credentials only.
          Set <code className="font-mono">CALLING_CENTRAL_ORG_ID</code> to ViteSEO&apos;s organization
          id, or set <code className="font-mono">CALLING_ORGANIZATION_CREDENTIALS=false</code> to return
          to the centralized default.{" "}
          <code className="font-mono">php artisan calling:doctor</code> runs the same check on the
          server.
        </p>
      </Card>
    );
  }

  if (!mode.organizationCredentials) {
    return (
      <Card className="mt-8 p-5">
        <p className="text-body font-medium text-foreground">
          Every organization uses ViteSEO&apos;s centralized Twilio account
        </p>
        <p className="mt-1 max-w-prose text-caption leading-relaxed text-muted-foreground">
          Per-organization Twilio accounts are switched off — the default. No client can configure an
          account of its own, and nothing on this deployment reads a stored one.
          {mode.centralAccountComplete
            ? ""
            : " The centralized account is not configured on this server either, so browser calling is unavailable and everyone calls manually."}
        </p>
      </Card>
    );
  }

  return null;
}

function CallingOverviewContent() {
  const { roles } = useAuth();
  const isSuperAdmin = (roles as unknown as string[]).includes("super_admin");

  const { data, isLoading, isError } = useCallingOverview();

  if (!isSuperAdmin) {
    return (
      <div className="grid min-h-screen place-items-center bg-background p-6 text-center">
        <div>
          <p className="text-h3 font-bold text-foreground">Super admin only</p>
          <Link href="/" className="mt-4 inline-block text-sm font-semibold text-brand hover:underline">
            ← Back to launcher
          </Link>
        </div>
      </div>
    );
  }

  const organizations = data?.organizations ?? [];
  const onBrowserCalling = organizations.filter((o) => o.browserCalling).length;
  const ownAccounts = organizations.filter((o) => o.source === "organization").length;
  // Started and not finished — the only rows an operator has to do anything about.
  const needsAttention = organizations.filter(
    (o) => !o.browserCalling && !o.centrallyManaged && (o.configured || o.enabled),
  ).length;

  return (
    <div className="min-h-screen bg-background text-foreground">
      <div className="mx-auto w-full max-w-4xl px-6 py-10">
        <Link
          href="/"
          className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" /> Launcher
        </Link>

        <div className="mt-4 flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-lg bg-brand text-brand-foreground">
            <PhoneCall className="size-5" />
          </span>
          <h1 className="text-h2 font-bold tracking-tight">Calling</h1>
        </div>
        <p className="mt-2 max-w-prose text-body text-muted-foreground">
          How browser calling is configured across every organization. Read-only — a client&apos;s
          Twilio account is changed by their own admin, in their own Settings → Integrations → Calling.
        </p>

        {isLoading ? (
          <p className="mt-8 text-sm text-muted-foreground">Loading calling configuration…</p>
        ) : isError || !data ? (
          <p className="mt-8 text-sm text-danger">Couldn&apos;t load the calling overview.</p>
        ) : (
          <>
            <DeploymentBanner mode={data.mode} />

            <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
              <StatCard label="On browser calling" value={onBrowserCalling} />
              <StatCard label="Own Twilio account" value={ownAccounts} />
              <StatCard label="Configured, not running" value={needsAttention} />
            </div>

            <section className="mt-8">
              <SectionTitle>Organizations</SectionTitle>
              <Card className="divide-y divide-border p-0">
                {organizations.map((org) => (
                  <OrgRow key={org.id} org={org} />
                ))}
              </Card>
            </section>
          </>
        )}
      </div>
    </div>
  );
}

export default function AdminCallingPage() {
  return (
    <RequireAuth>
      <CallingOverviewContent />
    </RequireAuth>
  );
}
