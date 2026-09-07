"use client";

import { useEffect, useState } from "react";

import { useAuth } from "@/core/auth-context";
import { PageHeader } from "@/core/components/page-header";
import { notify } from "@/core/lib/notify";
import { useOrgSettings, useUpdateOrgSettings } from "@/modules/accounting/hooks/useOrgSettings";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

function errMsg(err: unknown): string {
  const e = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
  const first = e?.response?.data?.errors && Object.values(e.response.data.errors)[0]?.[0];
  return first ?? e?.response?.data?.message ?? "Couldn’t save the settings.";
}

export default function OrgSettingsPage() {
  const { hasRole } = useAuth();
  const isAdmin = hasRole("acc_admin") || hasRole("super_admin");

  const { data: settings } = useOrgSettings();
  const save = useUpdateOrgSettings();

  const [name, setName] = useState("");
  const [tin, setTin] = useState("");
  const [address, setAddress] = useState("");
  const [isVat, setIsVat] = useState(false);
  const [defaultRate, setDefaultRate] = useState("");

  // Hydrate the form once the current values arrive.
  useEffect(() => {
    if (!settings) return;
    setName(settings.name ?? "");
    setTin(settings.tin ?? "");
    setAddress(settings.address ?? "");
    setIsVat(settings.is_vat_registered);
    setDefaultRate(settings.default_tax_rate != null ? String(settings.default_tax_rate) : "");
  }, [settings]);

  if (!isAdmin) {
    return (
      <>
        <PageHeader eyebrow="Admin" title="Business settings" subtitle="Your company identity on invoices." />
        <p className="mt-6 rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
          Finance Admin only.
        </p>
      </>
    );
  }

  const canSave = Boolean(name.trim()) && !save.isPending;

  const submit = () => {
    if (!canSave) return;
    const rate = defaultRate.trim() === "" ? null : Number.parseFloat(defaultRate);
    save.mutate(
      {
        name: name.trim(),
        tin: tin.trim() || null,
        address: address.trim() || null,
        is_vat_registered: isVat,
        default_tax_rate: rate != null && Number.isFinite(rate) ? rate : null,
      },
      {
        onSuccess: () => notify.success("Business settings saved."),
        onError: (e) => notify.error(errMsg(e)),
      },
    );
  };

  return (
    <>
      <PageHeader
        eyebrow="Admin"
        title="Business settings"
        subtitle="What prints on your invoices — company name, TIN, address, and VAT registration."
      />

      <div className="mt-6 max-w-2xl rounded-card border border-border bg-card p-6">
        <div className="grid gap-4">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Registered business name</span>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Your company's registered name" className={inputCls} />
          </label>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">TIN</span>
              <input value={tin} onChange={(e) => setTin(e.target.value)} placeholder="000-000-000-00000" className={inputCls} />
            </label>
            <label className="grid gap-1.5">
              <span className="text-sm font-medium">Default VAT rate (%)</span>
              <input
                type="number"
                min="0"
                max="100"
                step="0.01"
                value={defaultRate}
                onChange={(e) => setDefaultRate(e.target.value)}
                placeholder="12"
                className={inputCls}
              />
            </label>
          </div>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Registered address</span>
            <textarea
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              rows={3}
              placeholder="Unit / street / barangay / city / province"
              className="w-full rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
            />
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={isVat}
              onChange={(e) => setIsVat(e.target.checked)}
              className="h-4 w-4 rounded border-input"
            />
            <span>
              VAT-registered
              <span className="ml-1 text-muted-foreground">— new invoices prefill the default rate above</span>
            </span>
          </label>
        </div>

        <button
          type="button"
          disabled={!canSave}
          onClick={submit}
          className="mt-5 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
        >
          {save.isPending ? "Saving…" : "Save settings"}
        </button>
      </div>
    </>
  );
}
