"use client";

import { useState } from "react";
import { PageHeader } from "@/core/components/page-header";
import { EmployeeRecord } from "@/modules/hr/components/employee-record";
import { ChangeRequestModal } from "@/modules/hr/components/change-request-modal";
import { DocumentRequestModal } from "@/modules/hr/components/document-request-modal";
import { useMyEmployee } from "@/modules/hr/hooks/use-my-employee";

export default function ProfilePage() {
  const { data: employee, isLoading, isError } = useMyEmployee();
  const [changeOpen, setChangeOpen] = useState(false);
  const [docOpen, setDocOpen] = useState(false);

  return (
    <div className="p-8">
      <PageHeader
        title="My Profile"
        subtitle="Your employee record. Request detail changes and documents from HR."
      />

      <div className="mt-8">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading your profile…</p>
        ) : isError || !employee ? (
          <p className="text-sm text-destructive">Could not load your profile.</p>
        ) : (
          <>
            <EmployeeRecord
              employee={employee}
              onRequestChange={() => setChangeOpen(true)}
              onRequestDocument={() => setDocOpen(true)}
            />
            <ChangeRequestModal
              open={changeOpen}
              onOpenChange={setChangeOpen}
              employee={employee as unknown as Record<string, unknown>}
            />
            <DocumentRequestModal open={docOpen} onOpenChange={setDocOpen} />
          </>
        )}
      </div>
    </div>
  );
}
