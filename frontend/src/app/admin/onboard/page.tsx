"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Building2, Check } from "lucide-react";

import { RequireAuth, useAuth } from "@/core/auth-context";
import {
  useSystems,
  useCreateOrg,
  type SystemCatalog,
  type SystemTier,
  type CreatedOrg,
} from "@/modules/admin/onboarding";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const peso = (n: number) => `₱${n.toLocaleString("en-PH")}`;

export default function OnboardPage() {
  return (
    <RequireAuth>
      <OnboardContent />
    </RequireAuth>
  );
}

function OnboardContent() {
  const { roles } = useAuth();
  const isSuperAdmin = (roles as unknown as string[]).includes("super_admin");

  const { data: systems } = useSystems();
  const create = useCreateOrg();

  const [name, setName] = useState("");
  const [adminName, setAdminName] = useState("");
  const [adminEmail, setAdminEmail] = useState("");
  const [adminPassword, setAdminPassword] = useState("");
  // Availed systems → the chosen tier key. An un-availed system is simply absent.
  // CRM on its base tier is the default pick (constant keys the backend
  // validates); the operator can turn it off or add the others alongside.
  const [tiers, setTiers] = useState<Record<string, string>>({ crm: "basic" });
  // À-la-carte add-on feature keys stacked on top of the tiers.
  const [addons, setAddons] = useState<Set<string>>(new Set());
  const [created, setCreated] = useState<CreatedOrg | null>(null);

  // Full availing catalog straight from the backend systems registry
  // (HRIS, CRM, Projects) — each availed independently.
  const catalog = useMemo(() => systems ?? [], [systems]);

  if (!isSuperAdmin) {
    return (
      <div className="grid min-h-screen place-items-center bg-background p-6 text-center">
        <div>
          <p className="text-h3 font-bold text-foreground">Super admin only</p>
          <p className="mt-2 text-sm text-muted-foreground">
            You don’t have access to tenant onboarding.
          </p>
          <Link href="/" className="mt-4 inline-block text-sm font-semibold text-brand hover:underline">
            ← Back to launcher
          </Link>
        </div>
      </div>
    );
  }

  const defaultTierOf = (s: SystemCatalog): SystemTier | undefined =>
    s.tiers.find((t) => t.key === "basic") ?? s.tiers[0];

  const tierOf = (s: SystemCatalog): SystemTier | undefined =>
    s.tiers.find((t) => t.key === tiers[s.key]);

  const toggleSystem = (s: SystemCatalog) => {
    setTiers((prev) => {
      const next = { ...prev };
      if (next[s.key]) {
        delete next[s.key];
        // Drop this system's add-ons when it's un-availed.
        setAddons((a) => {
          const na = new Set(a);
          s.addons.forEach((ad) => na.delete(ad.key));
          return na;
        });
      } else {
        next[s.key] = defaultTierOf(s)?.key ?? s.tiers[0]?.key;
      }
      return next;
    });
  };

  const selectTier = (s: SystemCatalog, tier: SystemTier) => {
    setTiers((prev) => ({ ...prev, [s.key]: tier.key }));
    // An add-on the new tier already bundles is no longer à-la-carte.
    setAddons((a) => {
      const na = new Set(a);
      tier.features.forEach((f) => na.delete(f));
      return na;
    });
  };

  const toggleAddon = (key: string) => {
    setAddons((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  // Live price = Σ over availed systems of (tier + its selected add-ons).
  const systemTotal = (s: SystemCatalog): number => {
    const tier = tierOf(s);
    if (!tier) return 0;
    const extra = s.addons
      .filter((ad) => addons.has(ad.key) && !tier.features.includes(ad.key))
      .reduce((sum, ad) => sum + ad.price, 0);
    return tier.price + extra;
  };
  const grandTotal = catalog.reduce((sum, s) => sum + (tiers[s.key] ? systemTotal(s) : 0), 0);

  const availedCount = Object.keys(tiers).length;

  const canSubmit =
    Boolean(name) &&
    Boolean(adminName) &&
    Boolean(adminEmail) &&
    adminPassword.length >= 8 &&
    availedCount > 0 &&
    !create.isPending;

  const submit = () => {
    if (!canSubmit) return;
    create.mutate(
      {
        name,
        admin_name: adminName,
        admin_email: adminEmail,
        admin_password: adminPassword,
        systems: tiers,
        features: [...addons],
      },
      { onSuccess: (res) => setCreated(res) },
    );
  };

  const resetForm = () => {
    setName("");
    setAdminName("");
    setAdminEmail("");
    setAdminPassword("");
    setTiers({ crm: "basic" });
    setAddons(new Set());
    setCreated(null);
    create.reset();
  };

  return (
    <div className="min-h-screen bg-background text-foreground">
      <div className="mx-auto w-full max-w-3xl px-6 py-10">
        <Link
          href="/"
          className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="size-4" /> Launcher
        </Link>

        <div className="mt-4 flex items-center gap-2.5">
          <span className="flex size-9 items-center justify-center rounded-lg bg-brand text-brand-foreground">
            <Building2 className="size-5" />
          </span>
          <h1 className="text-h2 font-bold tracking-tight">Onboard a customer</h1>
        </div>
        <p className="mt-2 text-body text-muted-foreground">
          Create a new organization, pick the systems they avail (each on its own plan),
          and set up their first admin.
        </p>

        {created ? (
          <div className="mt-8 rounded-card border border-border bg-card p-6">
            <div className="flex items-center gap-2 text-brand">
              <Check className="size-5" />
              <p className="text-h3 font-bold">Organization created</p>
            </div>
            <dl className="mt-4 space-y-2 text-sm">
              <Row label="Organization" value={`${created.org.name} (#${created.org.id})`} />
              <Row label="Admin login" value={created.admin.email} />
              <Row
                label="Plan"
                value={`${created.subscription.plan} — ₱${created.subscription.monthly_price.toLocaleString("en-PH")}/mo (${created.subscription.status})`}
              />
              <Row label="Availed functions" value={created.features.join(", ")} />
            </dl>
            <p className="mt-4 text-caption text-muted-foreground">
              Share the admin’s email + the password you set. They start on a{" "}
              <strong>free trial</strong> with immediate access. Record their payment
              anytime from{" "}
              <Link href="/admin/billing" className="font-semibold text-brand hover:underline">
                Billing
              </Link>{" "}
              to convert the trial and keep access active.
            </p>
            {/*
              Browser calling is the one availed capability that needs the CLIENT to do something in a
              third-party console before it works, and nothing else in the product would ever tell them
              so. Shown only when they actually availed the CRM — a client without it has no such
              screen, and a next step they cannot take is worse than none.
            */}
            {created.features.some((f) => f.startsWith("crm")) ? (
              <p className="mt-3 text-caption text-muted-foreground">
                <strong>If they want calling from the browser:</strong> their admin sets it up in Zerem
                under <strong>Settings → Integrations → Calling</strong>, using their own Twilio
                account. They will need a Twilio Account SID and Auth Token, an API Key SID and Secret,
                a TwiML App SID, and an owned or verified Caller ID — and the TwiML App&apos;s Voice
                Request URL has to be the address that screen shows, set to POST. Send them the Twilio
                setup guide. Calling works without any of this: the team dials from their phones and
                logs outcomes in the CRM, exactly as before.
              </p>
            ) : null}

            <button
              type="button"
              onClick={resetForm}
              className="mt-5 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90"
            >
              Onboard another
            </button>
          </div>
        ) : (
          <>
            {/* Org + admin details */}
            <div className="mt-8 rounded-card border border-border bg-card p-6">
              <h2 className="text-h3 font-bold">Organization &amp; admin</h2>
              <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field label="Organization name">
                  <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Acme Trading OPC" className={inputCls} />
                </Field>
                <Field label="Admin full name">
                  <input value={adminName} onChange={(e) => setAdminName(e.target.value)} placeholder="e.g. Maria Santos" className={inputCls} />
                </Field>
                <Field label="Admin email">
                  <input type="email" value={adminEmail} onChange={(e) => setAdminEmail(e.target.value)} placeholder="admin@acme.ph" className={inputCls} />
                </Field>
                <Field label="Temporary password (min 8)">
                  <input type="text" value={adminPassword} onChange={(e) => setAdminPassword(e.target.value)} placeholder="At least 8 characters" className={inputCls} />
                </Field>
              </div>
            </div>

            {/* Systems to avail — each product on its own tier */}
            <div className="mt-6 rounded-card border border-border bg-card p-6">
              <h2 className="text-h3 font-bold">Systems to avail</h2>
              <p className="mt-1 text-caption text-muted-foreground">
                Turn on a system, pick its plan, then add extra functions. Each system is
                priced independently — the customer can avail any combination.
              </p>

              <div className="mt-4 space-y-4">
                {catalog.map((s) => {
                  const availed = Boolean(tiers[s.key]);
                  const tier = tierOf(s);
                  return (
                    <div
                      key={s.key}
                      className={
                        "rounded-card border p-4 transition-colors " +
                        (availed ? "border-brand bg-brand-subtle/20" : "border-border")
                      }
                    >
                      {/* Avail toggle + running per-system price */}
                      <label className="flex cursor-pointer items-center gap-2.5">
                        <input
                          type="checkbox"
                          checked={availed}
                          onChange={() => toggleSystem(s)}
                          className="size-4 accent-brand"
                        />
                        <span className="flex-1 text-sm font-bold text-foreground">{s.label}</span>
                        {availed ? (
                          <span className="text-sm font-bold tabular-nums text-dark-teal">
                            {peso(systemTotal(s))}
                            <span className="text-caption font-medium text-muted-foreground">/mo</span>
                          </span>
                        ) : (
                          <span className="text-caption text-muted-foreground">Not availed</span>
                        )}
                      </label>

                      {availed ? (
                        <>
                          {/* Tier picker */}
                          <div className="mt-4 grid gap-2 sm:grid-cols-4">
                            {s.tiers.map((t) => {
                              const active = t.key === tiers[s.key];
                              return (
                                <button
                                  key={t.key}
                                  type="button"
                                  onClick={() => selectTier(s, t)}
                                  className={
                                    "rounded-lg border p-3 text-left transition-colors " +
                                    (active
                                      ? "border-brand bg-brand-subtle/40"
                                      : "border-border hover:border-brand")
                                  }
                                >
                                  <p className="text-caption font-bold text-foreground">{t.label}</p>
                                  <p className="mt-0.5 text-sm font-bold tabular-nums text-dark-teal">
                                    {peso(t.price)}
                                    <span className="text-[10px] font-medium text-muted-foreground">/mo</span>
                                  </p>
                                </button>
                              );
                            })}
                          </div>

                          {/* À-la-carte functions on top of the tier */}
                          {s.addons.length > 0 ? (
                            <div className="mt-4">
                              <p className="text-label font-bold uppercase tracking-wide text-muted-foreground">
                                Extra functions
                              </p>
                              <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                {s.addons.map((ad) => {
                                  const included = tier?.features.includes(ad.key) ?? false;
                                  const checked = included || addons.has(ad.key);
                                  return (
                                    <label
                                      key={ad.key}
                                      title={included ? "Included in this plan." : undefined}
                                      className={
                                        "flex items-center gap-2.5 rounded-lg border px-3 py-2 text-sm " +
                                        (included
                                          ? "cursor-default border-border bg-muted/30"
                                          : "cursor-pointer border-border hover:border-brand")
                                      }
                                    >
                                      <input
                                        type="checkbox"
                                        checked={checked}
                                        disabled={included}
                                        onChange={() => !included && toggleAddon(ad.key)}
                                        className="size-4 accent-brand"
                                      />
                                      <span className="flex-1 text-foreground">{ad.label}</span>
                                      {included ? (
                                        <span className="text-caption font-semibold uppercase text-muted-foreground">
                                          Included
                                        </span>
                                      ) : (
                                        <span className="text-caption font-medium tabular-nums text-muted-foreground">
                                          +{peso(ad.price)}
                                        </span>
                                      )}
                                    </label>
                                  );
                                })}
                              </div>
                            </div>
                          ) : null}
                        </>
                      ) : null}
                    </div>
                  );
                })}
              </div>

              {/* Grand total */}
              <div className="mt-5 flex items-baseline justify-between border-t border-border pt-4">
                <span className="text-sm font-semibold text-foreground">
                  Total ({availedCount} {availedCount === 1 ? "system" : "systems"})
                </span>
                <span className="text-h3 font-bold tabular-nums text-dark-teal">
                  {peso(grandTotal)}
                  <span className="text-caption font-medium text-muted-foreground">/mo</span>
                </span>
              </div>
            </div>

            {create.isError ? (
              <p className="mt-4 text-sm text-destructive">
                Couldn’t create the organization. Check the email isn’t already used and try again.
              </p>
            ) : null}

            <button
              type="button"
              disabled={!canSubmit}
              onClick={submit}
              className="mt-6 inline-flex items-center rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {create.isPending ? "Creating…" : "Create organization"}
            </button>
          </>
        )}
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="grid gap-1.5">
      <span className="text-sm font-medium">{label}</span>
      {children}
    </label>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="font-medium text-foreground">{value}</dd>
    </div>
  );
}
