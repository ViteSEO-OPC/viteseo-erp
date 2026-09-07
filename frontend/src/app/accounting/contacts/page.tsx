"use client";

import { useState } from "react";

import { LoadFailed } from "@/modules/accounting/components/load-failed";
import { PageHeader } from "@/core/components/page-header";
import { SectionHeading } from "@/core/components/editorial";
import { DataTable, type Column } from "@/core/components/data-table";
import { useContacts, useCreateContact } from "@/modules/accounting/hooks/useContacts";
import { useAccountingPerms } from "@/modules/accounting/permissions";
import type { Contact } from "@/modules/accounting/types";

const inputCls =
  "h-9 w-full rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function ContactsPage() {
  const { data: contacts, isError } = useContacts();
  const create = useCreateContact();
  const { canManage } = useAccountingPerms();

  const [name, setName] = useState("");
  const [type, setType] = useState("customer");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [tin, setTin] = useState("");
  const [address, setAddress] = useState("");

  const canCreate = Boolean(name) && !create.isPending;

  const submit = () => {
    if (!canCreate) return;
    create.mutate(
      {
        name,
        type,
        email: email || undefined,
        phone: phone || undefined,
        tin: tin || undefined,
        address: address || undefined,
      },
      {
        onSuccess: () => {
          setName("");
          setEmail("");
          setPhone("");
          setTin("");
          setAddress("");
          setType("customer");
        },
      },
    );
  };

  const columns: Column<Contact>[] = [
    { header: "Name", cell: (r) => <span className="font-medium text-foreground">{r.name}</span> },
    {
      header: "Type",
      cell: (r) => (
        <span className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
          {r.type}
        </span>
      ),
    },
    { header: "TIN", cell: (r) => <span className="font-mono text-muted-foreground">{r.tin ?? "—"}</span> },
    { header: "Email", cell: (r) => <span className="text-muted-foreground">{r.email ?? "—"}</span> },
    { header: "Phone", cell: (r) => <span className="text-muted-foreground">{r.phone ?? "—"}</span> },
  ];

  return (
    <>
      <PageHeader
        eyebrow="Directory"
        title="Contacts"
        subtitle="Customers & vendors — reused across invoices and bills."
      />

      {canManage ? (
      <div className="mt-6 max-w-3xl rounded-card border border-border bg-card p-6">
        <SectionHeading title="New contact" />
        <div className="mt-4 grid gap-4 sm:grid-cols-2">
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Name</span>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Acme Corp" className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Type</span>
            <select value={type} onChange={(e) => setType(e.target.value)} className={inputCls}>
              <option value="customer">Customer</option>
              <option value="vendor">Vendor</option>
              <option value="both">Both</option>
            </select>
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Email (optional)</span>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="billing@example.com" className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Phone (optional)</span>
            <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="+63…" className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">TIN (for BIR / SLSP)</span>
            <input value={tin} onChange={(e) => setTin(e.target.value)} placeholder="000-000-000-000" className={inputCls} />
          </label>
          <label className="grid gap-1.5">
            <span className="text-sm font-medium">Address (optional)</span>
            <input value={address} onChange={(e) => setAddress(e.target.value)} placeholder="City / address" className={inputCls} />
          </label>
        </div>
        {create.isError ? (
          <p className="mt-3 text-caption text-destructive">Couldn’t save the contact. Check the fields.</p>
        ) : null}
        <button
          type="button"
          disabled={!canCreate}
          onClick={submit}
          className="mt-4 inline-flex items-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
        >
          {create.isPending ? "Saving…" : "Add contact"}
        </button>
      </div>
      ) : null}

      <div className="mt-6">
        {isError ? (
          <LoadFailed what="contacts" />
        ) : (
          <DataTable<Contact>
            columns={columns}
            rows={contacts ?? []}
            rowKey={(r) => r.id}
            pageSize={25}
            searchPlaceholder="Search contacts…"
            empty="No contacts yet — add one above."
          />
        )}
      </div>
    </>
  );
}
