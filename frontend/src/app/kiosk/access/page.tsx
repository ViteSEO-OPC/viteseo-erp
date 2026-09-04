"use client";

import { useState } from "react";
import { Copy, KeyRound } from "lucide-react";

import { PageHeader } from "@/core/components/page-header";
import { notify } from "@/core/lib/notify";
import { useConfirm } from "@/core/ui/use-confirm";
import { useKioskAccess, useCreateAccess, useRevokeAccess } from "@/modules/kiosk/hooks";
import type { KioskAccessCredentials, KioskAccessGrant } from "@/modules/kiosk/types";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

const STATUS_STYLE: Record<string, string> = {
  active: "bg-brand/10 text-brand",
  expired: "bg-muted text-muted-foreground",
  revoked: "bg-destructive/10 text-destructive",
};

function when(iso: string | null): string {
  if (!iso) return "—";
  return new Date(iso).toLocaleString("en-PH", { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
}

function copy(text: string) {
  navigator.clipboard?.writeText(text).then(
    () => notify.success("Copied."),
    () => notify.error("Couldn’t copy."),
  );
}

export default function KioskAccessPage() {
  const { data: grants, isLoading } = useKioskAccess();
  const create = useCreateAccess();
  const revoke = useRevokeAccess();
  const { confirm, confirmDialog } = useConfirm();

  const [label, setLabel] = useState("");
  const [creds, setCreds] = useState<KioskAccessCredentials | null>(null);

  const list = grants ?? [];

  const generate = () => {
    if (!label.trim() || create.isPending) return;
    create.mutate(label.trim(), {
      onSuccess: (res) => {
        setCreds(res);
        setLabel("");
      },
      onError: () => notify.error("Couldn’t generate access — please try again."),
    });
  };

  return (
    <>
      {confirmDialog}
      <PageHeader
        title="Client Access"
        subtitle="Generate a 1-day login so a client can try the kiosk on their own (long-distance demo)."
      />

      {/* Generate */}
      <div className="mt-6 rounded-card border border-border bg-card p-6">
        <h2 className="text-h3 font-bold">Generate 1-day access</h2>
        <div className="mt-4 flex flex-wrap items-end gap-3">
          <label className="grid flex-1 gap-1.5" style={{ minWidth: 220 }}>
            <span className="text-sm font-medium">Client / business name</span>
            <input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="e.g. Ana's Bakery" className={inputCls} />
          </label>
          <button
            type="button"
            disabled={!label.trim() || create.isPending}
            onClick={generate}
            className="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            <KeyRound className="size-4" /> {create.isPending ? "Generating…" : "Generate"}
          </button>
        </div>

        {/* Credentials — shown ONCE */}
        {creds ? (
          <div className="mt-4 rounded-card border border-brand bg-brand-subtle/30 p-4">
            <p className="text-sm font-bold text-foreground">Share these with {creds.label} — the password won’t be shown again.</p>
            <div className="mt-3 space-y-2">
              <CredRow label="Login URL" value={typeof window !== "undefined" ? `${window.location.origin}/login` : "/login"} />
              <CredRow label="Email" value={creds.email} />
              <CredRow label="Password" value={creds.password} />
            </div>
            <p className="mt-3 text-caption text-muted-foreground">
              Expires {when(creds.expires_at)} · access is limited to the website kiosk only.
            </p>
            <button
              type="button"
              onClick={() => setCreds(null)}
              className="mt-3 text-caption font-semibold text-muted-foreground hover:underline"
            >
              Done
            </button>
          </div>
        ) : null}
      </div>

      {/* Grants */}
      <div className="mt-6 overflow-x-auto rounded-card border border-border bg-card">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border">
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Client</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Login email</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Status</th>
              <th className="px-4 py-2.5 text-left text-label font-bold uppercase tracking-wide text-muted-foreground">Expires</th>
              <th className="px-4 py-2.5" />
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">Loading…</td></tr>
            ) : list.length === 0 ? (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">No client access issued yet.</td></tr>
            ) : (
              list.map((g: KioskAccessGrant) => (
                <tr key={g.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-2.5 font-medium text-foreground">{g.label}</td>
                  <td className="px-4 py-2.5 text-muted-foreground">{g.email ?? "—"}</td>
                  <td className="px-4 py-2.5">
                    <span className={`rounded-full px-2 py-0.5 text-caption font-semibold capitalize ${STATUS_STYLE[g.status] ?? "bg-muted text-muted-foreground"}`}>
                      {g.status}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-muted-foreground">{when(g.expires_at)}</td>
                  <td className="px-4 py-2.5 text-right">
                    {g.status === "active" ? (
                      <button
                        type="button"
                        onClick={() =>
                          confirm({
                            title: `Revoke access for ${g.label}?`,
                            description: "They won’t be able to use the kiosk anymore.",
                            confirmLabel: "Revoke",
                            onConfirm: () => revoke.mutate(g.id),
                          })
                        }
                        className="text-caption font-semibold text-destructive hover:underline"
                      >
                        Revoke
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}

function CredRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center gap-2">
      <span className="w-20 shrink-0 text-caption font-semibold text-muted-foreground">{label}</span>
      <code className="flex-1 truncate rounded bg-background px-2 py-1 text-caption text-foreground">{value}</code>
      <button
        type="button"
        onClick={() => copy(value)}
        className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-caption font-semibold text-foreground hover:bg-muted/40"
      >
        <Copy className="size-3" /> Copy
      </button>
    </div>
  );
}
