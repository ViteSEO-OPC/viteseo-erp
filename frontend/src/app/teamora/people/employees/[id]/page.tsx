"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import { EmployeeRecord } from "@/modules/hr/components/employee-record";
import { useEmployee } from "@/modules/hr/hooks/use-employees";

export default function EmployeeRecordPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { data: employee, isLoading, isError } = useEmployee(id);

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Employee"
        subtitle={employee ? `Viewing ${employee.name}` : undefined}
        actions={
          <Button variant="outline" asChild>
            <Link href="/teamora/people/employees">
              <ArrowLeft />
              Back to directory
            </Link>
          </Button>
        }
      />

      <div className="mt-8">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading record…</p>
        ) : isError || !employee ? (
          <p className="text-sm text-destructive">Could not load this employee.</p>
        ) : (
          // Each section on the record edits in place — no dialog.
          <EmployeeRecord employee={employee} isHr />
        )}
      </div>
    </div>
  );
}
