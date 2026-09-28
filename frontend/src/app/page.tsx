"use client";

import { useMemo } from "react";
import Link from "next/link";
import { Zap, LogOut, ArrowRight, Lock, CreditCard, Building2, Waypoints, PhoneCall } from "lucide-react";

import { useAuth, RequireAuth } from "@/core/auth-context";
import { getModuleTiles } from "@/core/access";
import { useFeatures } from "@/core/use-features";
import { ThemeToggle } from "@/core/components/theme-toggle";

/**
 * Post-login launcher for "Vite SEO Systems" — every user lands here first at
 * the site root `/`. Shows all three systems as cards: the ones the user's
 * roles/permissions grant are clickable (enter with no re-login — same token);
 * the rest are shown locked.
 *
 * The launcher lives INSIDE the shared auth guard but OUTSIDE any single
 * module's shell — it's the cross-module switchboard, so it wraps itself in
 * <RequireAuth> (bounces to /login when unauthenticated) without mounting a
 * module sidebar.
 */
export default function LauncherPage() {
  return (
    <RequireAuth>
      <LauncherContent />
    </RequireAuth>
  );
}

function LauncherContent() {
  const { user, roles, permissions, logout } = useAuth();
  const { data: features } = useFeatures();

  const tiles = useMemo(
    () => getModuleTiles(roles as unknown as string[], permissions, features?.modules),
    [roles, permissions, features?.modules],
  );
  const accessibleCount = tiles.filter((t) => t.accessible).length;
  const isSuperAdmin = (roles as unknown as string[]).includes("super_admin");

  const firstName = user?.name?.split(" ")[0] ?? "there";

  // Time-aware greeting in Manila time (matches the employee overview).
  const manilaHour = Number(
    new Date().toLocaleString("en-PH", {
      timeZone: "Asia/Manila",
      hour: "2-digit",
      hour12: false,
    }),
  );
  const greeting =
    manilaHour < 12 ? "Good morning" : manilaHour < 18 ? "Good afternoon" : "Good evening";

  return (
    <div className="min-h-screen bg-background text-foreground">
      <div className="mx-auto flex min-h-screen w-full max-w-5xl flex-col px-6 py-10">
        {/* Brand + sign out */}
        <header className="flex items-center justify-between">
          <div className="flex items-center gap-2.5">
            <span className="flex size-9 items-center justify-center rounded-lg bg-brand text-brand-foreground">
              <Zap className="size-5" fill="currentColor" />
            </span>
            <span className="text-h3 font-bold">Vite SEO Systems</span>
          </div>
          <div className="flex items-center gap-1">
            <ThemeToggle />
            <button
              type="button"
              onClick={() => logout()}
              className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
            >
              <LogOut className="size-4" />
              Sign out
            </button>
          </div>
        </header>

        {/* Greeting */}
        <div className="mt-12">
          <h1 className="text-h1 font-bold tracking-tight">
            {greeting}, {firstName}.
          </h1>
          <p className="mt-2 text-body text-muted-foreground">
            {accessibleCount > 0
              ? "Choose a system to get started."
              : "You don’t have access to any system yet — contact your administrator."}
          </p>
        </div>

        {/* Module cards — all three always shown; locked when the user has no access. */}
        <div className="mt-10 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {tiles.map((mod) => {
            const Icon = mod.icon;

            // Not for sale and not held: show nothing at all. A lock here would be
            // advertising something this viewer can never buy. See hideWhenLocked.
            if (!mod.accessible && mod.hideWhenLocked) {
              return null;
            }

            // Locked card — no hover, no click, greyed out.
            if (!mod.accessible) {
              return (
                <div
                  key={mod.key}
                  aria-disabled="true"
                  title="You don’t have access to this system"
                  className="flex cursor-not-allowed select-none flex-col rounded-card border border-border bg-muted/30 p-6 opacity-60"
                >
                  <div className="flex items-start justify-between">
                    <span className="flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
                      <Icon className="size-6" />
                    </span>
                    <Lock className="size-4 text-muted-foreground" />
                  </div>
                  <div className="mt-5">
                    <div className="flex items-baseline gap-2">
                      <h2 className="text-h3 font-bold text-muted-foreground">{mod.title}</h2>
                      <span className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
                        {mod.subtitle}
                      </span>
                    </div>
                    <p className="mt-1.5 text-caption text-muted-foreground">No access</p>
                  </div>
                </div>
              );
            }

            return (
              <Link
                key={mod.key}
                href={mod.href}
                className="group flex flex-col rounded-card border border-border bg-card p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <div className="flex items-start justify-between">
                  <span className="flex size-12 items-center justify-center rounded-xl bg-brand-subtle text-dark-teal">
                    <Icon className="size-6" />
                  </span>
                  <ArrowRight className="size-5 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-brand" />
                </div>
                <div className="mt-5">
                  <div className="flex items-baseline gap-2">
                    <h2 className="text-h3 font-bold">{mod.title}</h2>
                    <span className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
                      {mod.subtitle}
                    </span>
                  </div>
                  <p className="mt-1.5 text-caption text-muted-foreground">
                    {mod.description}
                  </p>
                </div>
              </Link>
            );
          })}
        </div>

        {/* Admin — only for super admins (platform owner). */}
        {isSuperAdmin ? (
          <div className="mt-12">
            <h2 className="text-label font-bold uppercase tracking-wide text-muted-foreground">Admin</h2>
            <div className="mt-3 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
              <Link
                href="/admin/billing"
                className="group flex flex-col rounded-card border border-border bg-card p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand hover:shadow-md"
              >
                <div className="flex items-start justify-between">
                  <span className="flex size-12 items-center justify-center rounded-xl bg-brand-subtle text-dark-teal">
                    <CreditCard className="size-6" />
                  </span>
                  <ArrowRight className="size-5 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-brand" />
                </div>
                <div className="mt-5">
                  <h2 className="text-h3 font-bold">Billing</h2>
                  <p className="mt-1.5 text-caption text-muted-foreground">
                    Subscriptions — who’s on trial, paid, or suspended. Record payments.
                  </p>
                </div>
              </Link>
              <Link
                href="/admin/onboard"
                className="group flex flex-col rounded-card border border-border bg-card p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand hover:shadow-md"
              >
                <div className="flex items-start justify-between">
                  <span className="flex size-12 items-center justify-center rounded-xl bg-brand-subtle text-dark-teal">
                    <Building2 className="size-6" />
                  </span>
                  <ArrowRight className="size-5 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-brand" />
                </div>
                <div className="mt-5">
                  <h2 className="text-h3 font-bold">Onboard a customer</h2>
                  <p className="mt-1.5 text-caption text-muted-foreground">
                    Create a new organization + its first admin, and pick a plan.
                  </p>
                </div>
              </Link>
              <Link
                href="/admin/integrations"
                className="group flex flex-col rounded-card border border-border bg-card p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand hover:shadow-md"
              >
                <div className="flex items-start justify-between">
                  <span className="flex size-12 items-center justify-center rounded-xl bg-brand-subtle text-dark-teal">
                    <Waypoints className="size-6" />
                  </span>
                  <ArrowRight className="size-5 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-brand" />
                </div>
                <div className="mt-5">
                  <h2 className="text-h3 font-bold">Integrations</h2>
                  <p className="mt-1.5 text-caption text-muted-foreground">
                    Connect a customer’s systems — turn cross-module data flow on or off.
                  </p>
                </div>
              </Link>
              {/*
                Read-only. A client's Twilio account is changed by their own admin on their own
                settings screen; this is the only place the platform can SEE all of them at once,
                because Zerem has no organization switcher.
              */}
              <Link
                href="/admin/calling"
                className="group flex flex-col rounded-card border border-border bg-card p-6 shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand hover:shadow-md"
              >
                <div className="flex items-start justify-between">
                  <span className="flex size-12 items-center justify-center rounded-xl bg-brand-subtle text-dark-teal">
                    <PhoneCall className="size-6" />
                  </span>
                  <ArrowRight className="size-5 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-brand" />
                </div>
                <div className="mt-5">
                  <h2 className="text-h3 font-bold">Calling</h2>
                  <p className="mt-1.5 text-caption text-muted-foreground">
                    Which customers have browser calling set up, and what each one is still missing.
                  </p>
                </div>
              </Link>
            </div>
          </div>
        ) : null}

        <div className="flex-1" />
      </div>
    </div>
  );
}
