"use client";

import { PageHeader } from "@/modules/hr/components/page-header";
import { InternAllowanceForm } from "@/modules/hr/components/intern-allowance/intern-allowance-form";
import { InternAllowanceVersions } from "@/modules/hr/components/intern-allowance/version-history";
import { useInternAllowanceSettings } from "@/modules/hr/hooks/use-intern-allowance";

/** Company → Intern Allowance. */
export default function InternAllowancePage() {
  const { data, isLoading, isError } = useInternAllowanceSettings();

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Intern Allowance"
        subtitle="Configure how intern allowances are calculated and paid, based on your company's internship policy."
      />

      <div className="mt-8 space-y-8">
        {isError ? (
          <p className="text-sm text-destructive">Could not load the intern allowance rules. Please refresh.</p>
        ) : isLoading || !data ? (
          <p className="text-sm text-muted-foreground">Loading intern allowance rules…</p>
        ) : (
          <>
            {/* Re-keyed on the version in force, so a scheduled version taking effect resets the form. */}
            <InternAllowanceForm key={data.effective_from ?? "none"} overview={data} />
            <InternAllowanceVersions overview={data} />
          </>
        )}
      </div>
    </div>
  );
}
