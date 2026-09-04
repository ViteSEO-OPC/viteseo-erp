"use client";

import { AlertTriangle, Clock, Mail, Phone } from "lucide-react";

import { useFeatures } from "@/core/use-features";
import { BILLING_CONTACT } from "@/core/billing-contact";

/** Whole days from now until an ISO date (0 if past/invalid). */
function daysLeft(iso: string | null): number {
  if (!iso) return 0;
  const ms = new Date(iso).getTime() - Date.now();
  return Math.max(0, Math.ceil(ms / (1000 * 60 * 60 * 24)));
}

/**
 * Prepaid gate for the product UI. Mirrors the backend `subscription-active`
 * middleware: if the org's subscription isn't active/trialing, we show a paywall
 * instead of the app. While trialing, a banner nudges toward payment. Orgs with
 * no subscription (e.g. the provider / super-admin) pass through untouched.
 */
export function SubscriptionGuard({ children }: { children: React.ReactNode }) {
  const { data, isLoading } = useFeatures();

  // Don't flash a paywall while we don't know yet.
  if (isLoading) return <>{children}</>;

  const sub = data?.subscription ?? null;
  if (!sub) return <>{children}</>;

  if (!sub.is_active) {
    const suspended = sub.status === "suspended";
    return (
      <div className="grid min-h-[60vh] place-items-center px-6 text-center">
        <div className="max-w-md">
          <span className="mx-auto flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
            <AlertTriangle className="size-6" />
          </span>
          <h2 className="mt-4 text-h2 font-bold text-foreground">
            {suspended ? "Subscription paused" : "Trial ended"}
          </h2>
          <p className="mt-2 text-body text-muted-foreground">
            {suspended
              ? "Your access is paused because the subscription lapsed."
              : "Your free trial has ended."}{" "}
            Settle your{" "}
            <span className="font-semibold text-foreground">
              {sub.plan}
            </span>{" "}
            plan (₱{sub.monthly_price.toLocaleString("en-PH")}/mo) to continue.
          </p>

          <div className="mt-5 rounded-lg border border-border bg-card p-4 text-left">
            <p className="text-sm font-bold text-foreground">How to reactivate</p>
            <ol className="mt-2 list-decimal space-y-1 pl-5 text-caption text-muted-foreground">
              {BILLING_CONTACT.steps.map((s) => <li key={s}>{s}</li>)}
            </ol>

            <div className="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-caption">
              <a href={`mailto:${BILLING_CONTACT.email}`} className="inline-flex items-center gap-1.5 font-semibold text-brand hover:underline">
                <Mail className="size-3.5" /> {BILLING_CONTACT.email}
              </a>
              <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                <Phone className="size-3.5" /> {BILLING_CONTACT.phone}
              </span>
            </div>

            {BILLING_CONTACT.methods.length > 0 ? (
              <div className="mt-3 border-t border-border pt-3">
                <p className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">Payment methods</p>
                <ul className="mt-1.5 space-y-1 text-caption text-muted-foreground">
                  {BILLING_CONTACT.methods.map((m) => (
                    <li key={m.label}>
                      <span className="font-semibold text-foreground">{m.label}:</span> {m.detail}
                    </li>
                  ))}
                </ul>
                {BILLING_CONTACT.qr.length > 0 ? (
                  <div className="mt-3 flex flex-wrap gap-4">
                    {BILLING_CONTACT.qr.map((q) => (
                      <figure key={q.label} className="text-center">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={q.src} alt={`${q.label} QR`} className="size-32 rounded-lg border border-border" />
                        <figcaption className="mt-1 text-caption text-muted-foreground">{q.label}</figcaption>
                      </figure>
                    ))}
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  return (
    <>
      {sub.status === "trialing" ? (
        <div className="mb-5 flex items-center gap-2 rounded-lg border border-warning/40 bg-warning/10 px-4 py-2.5 text-sm text-foreground">
          <Clock className="size-4 shrink-0 text-warning" />
          <span>
            <strong>Free trial</strong> — {daysLeft(sub.current_period_end)} day
            {daysLeft(sub.current_period_end) === 1 ? "" : "s"} left. Contact Vite
            SEO to activate your {sub.plan} plan and keep access.
          </span>
        </div>
      ) : null}
      {children}
    </>
  );
}
