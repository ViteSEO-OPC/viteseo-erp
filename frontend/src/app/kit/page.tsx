"use client";

// DEV-ONLY kit preview — renders every core component against the Style Guide.
// Not linked in nav; visit /kit directly. Safe to delete after Slice 2.5 review.

import { notify as toast } from "@/core/lib/notify";
import { Check, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { StatCard } from "@/core/components/stat-card";
import { StatusBadge } from "@/core/components/status-badge";
import { ProgressBar } from "@/core/components/progress-bar";
import { DataTable, PrimaryCell, type Column } from "@/core/components/data-table";

type Row = { name: string; role: string; dept: string; status: string };

const rows: Row[] = [
  { name: "Maya Villanueva", role: "Product Manager", dept: "Product", status: "approved" },
  { name: "Ben Kim", role: "Engineer", dept: "Engineering", status: "pending" },
  { name: "Carl Reyes", role: "Sales Lead", dept: "Sales", status: "rejected" },
  { name: "Elena Sokolova", role: "Designer", dept: "Product", status: "in_progress" },
];

const columns: Column<Row>[] = [
  { header: "Employee", cell: (r) => <PrimaryCell title={r.name} subtitle={r.role} /> },
  { header: "Department", cell: (r) => r.dept },
  { header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
  {
    header: "Actions",
    align: "right",
    cell: () => (
      <div className="flex justify-end gap-1.5">
        <Button size="icon-sm" variant="success">
          <Check />
        </Button>
        <Button size="icon-sm" variant="destructive">
          <X />
        </Button>
      </div>
    ),
  },
];

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3">
      <h2 className="text-h3 font-bold text-foreground">{title}</h2>
      {children}
    </section>
  );
}

export default function KitPreviewPage() {
  return (
    <div className="mx-auto max-w-5xl space-y-10 p-8">
      <PageHeader
        title="UI Kit Preview"
        subtitle="Every core component, styled from the Figma Style Guide (Exo 2 + tokens)."
        actions={<Button onClick={() => toast.success("Toast fired ✓")}>Test toast</Button>}
      />

      <Section title="Stat cards">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <StatCard label="Total Employees" value="248" subNote="+6 this month" highlight />
          <StatCard label="On Leave Today" value="7" subNote="3 sick · 4 annual" />
          <StatCard label="Open Roles" value="9" subNote="3 closing this week" />
        </div>
      </Section>

      <Section title="Status badges">
        <div className="flex flex-wrap gap-2">
          {["approved", "paid", "pending", "rejected", "declined", "in_progress"].map((s) => (
            <StatusBadge key={s} status={s} />
          ))}
        </div>
      </Section>

      <Section title="Progress bars (leave balances)">
        <div className="max-w-sm space-y-3">
          <div>
            <p className="mb-1 text-caption text-muted-foreground">Annual · 12 of 15 left</p>
            <ProgressBar value={12} max={15} />
          </div>
          <div>
            <p className="mb-1 text-caption text-muted-foreground">Sick · 9 of 10 left</p>
            <ProgressBar value={9} max={10} />
          </div>
        </div>
      </Section>

      <Section title="Buttons">
        <div className="flex flex-wrap gap-2">
          <Button>Primary</Button>
          <Button variant="success">Approve</Button>
          <Button variant="destructive">Reject</Button>
          <Button variant="outline">Outline</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="ghost">Ghost</Button>
        </div>
      </Section>

      <Section title="Data table">
        <DataTable columns={columns} rows={rows} rowKey={(r) => r.name} />
      </Section>
    </div>
  );
}
