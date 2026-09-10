"use client";

import Link from "next/link";
import { useState } from "react";

import { PageHeader } from "@/core/components/page-header";
import { formatPeso } from "@/core/lib/format";
import { useVatReport } from "@/modules/accounting/hooks/useReports";
import { manilaMonth } from "@/modules/accounting/lib/dates";

const inputCls =
  "h-9 rounded-lg border border-input bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export default function VatReportPage() {
  const [period, setPeriod] = useState(() => manilaMonth());
  const { data, isLoading, isError } = useVatReport(period);
  const net = data?.net_vat_payable ?? 0;

  return (
    <>
      <PageHeader
        eyebrow="Reports"
        title="VAT (BIR)"
        subtitle="Output VAT on sales minus Input VAT on purchases — your net VAT payable to the BIR."
        actions={
          <input
            type="month"
            value={period}
            onChange={(e) => setPeriod(e.target.value)}
            className={inputCls}
          />
        }
      />

      {isError ? (
        <p className="mt-6 rounded-card border border-border bg-card p-6 text-sm text-muted-foreground">
          Couldn’t load the VAT report. Please try again.
        </p>
      ) : (
        <>
          <div className="mt-6 grid gap-4 sm:grid-cols-3">
            <Card label="Output VAT (on sales)" value={data?.output_vat ?? 0} loading={isLoading} />
            <Card label="Input VAT (on purchases)" value={data?.input_vat ?? 0} loading={isLoading} />
            <Card
              label={net >= 0 ? "Net VAT payable to BIR" : "Net creditable VAT"}
              value={Math.abs(net)}
              loading={isLoading}
              tone={net > 0 ? "bad" : "good"}
            />
          </div>

          <p className="mt-4 max-w-2xl text-caption text-muted-foreground">
            Output VAT = 12% collected on sales (Output VAT Payable). Input VAT = 12% paid on
            purchases (Input VAT). If the net is positive, you remit it to the BIR; if negative, it
            carries over as creditable input VAT. For the quarterly figures to transcribe into
            eBIRForms, see{" "}
            <Link href="/accounting/reports/bir-2550q" className="underline underline-offset-2">
              BIR 2550Q
            </Link>
            .
          </p>
        </>
      )}
    </>
  );
}

function Card({
  label,
  value,
  loading,
  tone,
}: {
  label: string;
  value: number;
  loading: boolean;
  tone?: "good" | "bad";
}) {
  return (
    <div className="rounded-card border border-border bg-card p-5">
      <p className="text-caption font-semibold uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p
        className={
          "mt-1 text-h2 font-bold tabular-nums " +
          (tone === "bad" ? "text-destructive" : tone === "good" ? "text-brand" : "text-foreground")
        }
      >
        {loading ? "…" : formatPeso(value)}
      </p>
    </div>
  );
}
