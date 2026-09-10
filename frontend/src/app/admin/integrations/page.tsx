"use client";

import { useMemo } from "react";
import Link from "next/link";
import {
  ArrowLeft,
  ArrowRight,
  Users,
  Contact,
  Lock,
  Waypoints,
  type LucideIcon,
} from "lucide-react";

import { RequireAuth, useAuth } from "@/core/auth-context";
import { Badge, Card, StatCard } from "@/core/ui";
import { cn } from "@/core/lib/utils";
import { notify } from "@/core/lib/notify";

import { useConnections, useToggleConnection, type Connection } from "@/core/use-connections";

function errMsg(err: unknown): string {
  const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
  const first = e?.response?.data?.errors && Object.values(e.response.data.errors)[0]?.[0];
  return first ?? e?.response?.data?.message ?? "Something went wrong.";
}

// Each system gets its own icon + soft-tone chip so a connection reads as a
// flow between two recognisable products, not two anonymous labels.
const SYSTEM: Record<string, { icon: LucideIcon; chip: string }> = {
  hr: { icon: Users, chip: "bg-success-soft text-success" },
  crm: { icon: Contact, chip: "bg-danger-soft text-danger" },
};

function SystemChip({ system, dim }: { system: string; dim?: boolean }) {
  const s = SYSTEM[system] ?? { icon: Waypoints, chip: "bg-black/5 text-ink-soft dark:bg-white/10" };
  const Icon = s.icon;
  return (
    <span
      className={cn(
        "flex size-10 items-center justify-center rounded-xl transition-opacity",
        s.chip,
        dim && "opacity-40",
      )}
    >
      <Icon className="size-5" />
    </span>
  );
}

/** The from → to identity of a connection: two system chips joined by an arrow. */
function Flow({ conn, on }: { conn: Connection; on: boolean }) {
  return (
    <div className="flex shrink-0 items-center gap-2">
      <SystemChip system={conn.from} dim={!on} />
      <ArrowRight className={cn("size-4 transition-colors", on ? "text-brand" : "text-muted-foreground/50")} />
      <SystemChip system={conn.to} dim={!on} />
    </div>
  );
}

/** Small iOS-style on/off switch (the kit has no Switch primitive). */
function Toggle({ on, onClick, disabled }: { on: boolean; onClick: () => void; disabled?: boolean }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      onClick={onClick}
      disabled={disabled}
      className={cn(
        "relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40",
        on ? "bg-brand" : "bg-black/15 dark:bg-white/20",
        disabled && "cursor-not-allowed opacity-50",
      )}
    >
      <span
        className={cn(
          "inline-block size-5 rounded-full bg-white shadow-sm transition-transform",
          on ? "translate-x-[22px]" : "translate-x-0.5",
        )}
      />
    </button>
  );
}

/** A live, toggleable connection (both systems availed). */
function ConnectionRow({ conn }: { conn: Connection }) {
  const toggle = useToggleConnection();

  async function flip() {
    try {
      await toggle.mutateAsync({ key: conn.key, enabled: !conn.enabled });
      notify.success(conn.enabled ? `${conn.label} turned off` : `${conn.label} turned on`);
    } catch (err) {
      notify.error(errMsg(err));
    }
  }

  return (
    <div className="flex items-center gap-5 p-5">
      <Flow conn={conn} on={conn.enabled} />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <h3 className="truncate text-body font-semibold text-foreground">{conn.label}</h3>
          <Badge tone={conn.enabled ? "success" : "neutral"}>{conn.enabled ? "On" : "Off"}</Badge>
        </div>
        <p className="mt-1 max-w-prose text-caption leading-relaxed text-muted-foreground">{conn.description}</p>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-1.5">
        <Toggle on={conn.enabled} onClick={flip} disabled={toggle.isPending} />
        <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground/70">
          {conn.enabled ? "Linked" : "Paused"}
        </span>
      </div>
    </div>
  );
}

/** A connection not yet usable — coming soon, or waiting on a system to be availed. */
function LockedRow({ conn }: { conn: Connection }) {
  const comingSoon = conn.status === "coming_soon";
  const needed = Object.values(conn.requires_labels);

  return (
    <div className="flex items-center gap-5 p-5">
      <Flow conn={conn} on={false} />
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <h3 className="truncate text-body font-semibold text-muted-foreground">{conn.label}</h3>
          <Badge tone={comingSoon ? "info" : "neutral"}>{comingSoon ? "Coming soon" : "Locked"}</Badge>
        </div>
        <p className="mt-1 max-w-prose text-caption leading-relaxed text-muted-foreground">{conn.description}</p>
        {!comingSoon && needed.length ? (
          <p className="mt-1.5 text-caption text-muted-foreground/80">
            <Lock className="mr-1 inline size-3 -translate-y-px" />
            Avail <span className="font-medium text-foreground">{needed.join(" + ")}</span> to unlock.
          </p>
        ) : null}
      </div>
      <div className="flex shrink-0 items-center text-muted-foreground/40">
        <Lock className="size-4" />
      </div>
    </div>
  );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <h2 className="mb-3 text-caption font-semibold uppercase tracking-wide text-muted-foreground">{children}</h2>
  );
}

function IntegrationsContent() {
  const { roles } = useAuth();
  const isSuperAdmin = (roles as unknown as string[]).includes("super_admin");

  const { data, isLoading, isError } = useConnections();
  // Nothing registered at all is a different story from "you haven't availed the
  // second system yet" — the page has to say so instead of showing empty stats.
  const nothingRegistered = (data ?? []).length === 0;

  const { available, locked, stats } = useMemo(() => {
    const list = data ?? [];
    const avail = list.filter((c) => c.status === "available" && c.available);
    return {
      available: avail,
      locked: list.filter((c) => !(c.status === "available" && c.available)),
      stats: {
        connected: avail.filter((c) => c.enabled).length,
        available: avail.length,
        comingSoon: list.filter((c) => c.status === "coming_soon").length,
      },
    };
  }, [data]);

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
            <Waypoints className="size-5" />
          </span>
          <h1 className="text-h2 font-bold tracking-tight">Integrations</h1>
        </div>
        <p className="mt-2 max-w-prose text-body text-muted-foreground">
          Connect a customer&apos;s systems so their data flows between modules. A connection lights up once both
          systems are availed — flip it on or off any time.
        </p>

        {isLoading ? (
          <p className="mt-8 text-sm text-muted-foreground">Loading integrations…</p>
        ) : isError ? (
          <p className="mt-8 text-sm text-danger">Couldn&apos;t load your integrations.</p>
        ) : nothingRegistered ? (
          <Card className="mt-8 p-8 text-center">
            <Waypoints className="mx-auto mb-3 size-8 text-muted-foreground/50" />
            <p className="text-body font-medium text-foreground">No integrations available</p>
            <p className="mx-auto mt-1 max-w-md text-caption text-muted-foreground">
              None of the systems on this platform share data with another one yet. When a
              connection ships, it shows up here ready to switch on.
            </p>
          </Card>
        ) : (
          <div className="mt-8 space-y-8">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <StatCard label="Live connections" value={stats.connected} />
              <StatCard label="Ready to connect" value={stats.available} />
              <StatCard label="On the roadmap" value={stats.comingSoon} />
            </div>

            <section>
              <SectionTitle>Your connections</SectionTitle>
              {available.length ? (
                <Card className="divide-y divide-border p-0">
                  {available.map((c) => (
                    <ConnectionRow key={c.key} conn={c} />
                  ))}
                </Card>
              ) : (
                <Card className="p-8 text-center">
                  <Waypoints className="mx-auto mb-3 size-8 text-muted-foreground/50" />
                  <p className="text-body font-medium text-foreground">No connections yet</p>
                  <p className="mx-auto mt-1 max-w-md text-caption text-muted-foreground">
                    Avail two systems that can share data and their connection appears here, ready to
                    switch on.
                  </p>
                </Card>
              )}
            </section>

            {locked.length ? (
              <section>
                <SectionTitle>More connections</SectionTitle>
                <Card className="divide-y divide-border p-0">
                  {locked.map((c) => (
                    <LockedRow key={c.key} conn={c} />
                  ))}
                </Card>
              </section>
            ) : null}
          </div>
        )}
      </div>
    </div>
  );
}

export default function IntegrationsPage() {
  return (
    <RequireAuth>
      <IntegrationsContent />
    </RequireAuth>
  );
}
