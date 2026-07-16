"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/core/components/page-header";
import { EmployeeRecord } from "@/modules/hr/components/employee-record";
import {
  EmployeeFormDialog,
  type EmployeeEditSection,
} from "@/modules/hr/components/employee-form-dialog";
import { useEmployee } from "@/modules/hr/hooks/use-employees";

export default function EmployeeRecordPage() {
  const params = useParams<{ id: string }>();
  const id = Number(params.id);
  const { data: employee, isLoading, isError } = useEmployee(id);
  const [editOpen, setEditOpen] = useState(false);
  const [editSection, setEditSection] = useState<EmployeeEditSection>("personal");

  return (
    <div className="p-8">
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
          <p className="text-sm text-destructive">
            Could not load this employee.
          </p>
        ) : (
          <EmployeeRecord
            employee={employee}
            onEdit={(tab) => {
              // Only personal/job/compensation are editable (guarded in EmployeeRecord).
              setEditSection(tab as EmployeeEditSection);
              setEditOpen(true);
            }}
          />
        )}
      </div>

      {employee && (
        <EmployeeFormDialog
          open={editOpen}
          onOpenChange={setEditOpen}
          employee={employee}
          section={editSection}
        />
      )}
    </div>
  );
}
