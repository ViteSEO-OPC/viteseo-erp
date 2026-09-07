"use client";

import { AgedReportView } from "../../_components/AgedReportView";
import { useAgedPayables } from "@/modules/accounting/hooks/useReports";

export default function AgedPayablesPage() {
  const { data, isLoading, isError } = useAgedPayables();

  return (
    <AgedReportView
      title="Aged Payables"
      subtitle="Unpaid bills — what you owe, and what’s due."
      nameLabel="Vendor"
      report={data}
      isLoading={isLoading}
      isError={isError}
    />
  );
}
