"use client";

import { AgedReportView } from "../../_components/AgedReportView";
import { useAgedReceivables } from "@/modules/accounting/hooks/useReports";

export default function AgedReceivablesPage() {
  const { data, isLoading, isError } = useAgedReceivables();

  return (
    <AgedReportView
      title="Aged Receivables"
      subtitle="Unpaid invoices — who owes you, and how overdue."
      nameLabel="Customer"
      report={data}
      isLoading={isLoading}
      isError={isError}
    />
  );
}
