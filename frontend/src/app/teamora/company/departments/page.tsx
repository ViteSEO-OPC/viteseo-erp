"use client";

import { useState } from "react";
import { Plus, Pencil, Trash2, Archive, RotateCcw } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { PageHeader } from "@/modules/hr/components/page-header";
import {
  DataTable,
  PrimaryCell,
  type Column,
} from "@/core/components/data-table";
import { StatusBadge } from "@/core/components/status-badge";
import { DepartmentFormDialog } from "@/modules/hr/components/department-form-dialog";
import {
  useDepartments,
  useDeleteDepartment,
  useToggleDepartmentArchive,
} from "@/modules/hr/hooks/use-departments";
import type { Department } from "@/modules/hr/types/department";
import { DeleteConfirmDialog } from "@/modules/hr/components/delete-confirm-dialog";

export default function DepartmentsPage() {
  const { data: departments, isLoading, isError } = useDepartments();
  const deleteMut = useDeleteDepartment();
  const archiveMut = useToggleDepartmentArchive();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Department | null>(null);
  const [deleting, setDeleting] = useState<Department | null>(null);

  function openCreate() {
    setEditing(null);
    setDialogOpen(true);
  }

  function openEdit(dept: Department) {
    setEditing(dept);
    setDialogOpen(true);
  }

  async function performDelete(dept: Department) {
    try {
      await deleteMut.mutateAsync(dept.id);
      toast.success("Department deleted");
    } catch {
      toast.error("Could not delete the department.");
    }
  }

  async function onToggleArchive(dept: Department) {
    try {
      await archiveMut.mutateAsync({ id: dept.id, restore: !dept.is_active });
      toast.success(dept.is_active ? "Department archived" : "Department restored");
    } catch {
      toast.error("Could not update the department.");
    }
  }

  const columns: Column<Department>[] = [
    {
      header: "Department",
      cell: (d) => <PrimaryCell title={d.name} subtitle={d.code} />,
    },
    {
      header: "Status",
      cell: (d) => (
        <StatusBadge
          status={d.is_active ? "active" : "inactive"}
          label={d.is_active ? "Active" : "Inactive"}
        />
      ),
    },
    {
      header: "Actions",
      align: "right",
      cell: (d) => (
        <div className="flex justify-end gap-1">
          <Button size="icon-sm" variant="ghost" onClick={() => openEdit(d)}>
            <Pencil />
            <span className="sr-only">Edit</span>
          </Button>
          <Button
            size="icon-sm"
            variant="ghost"
            onClick={() => onToggleArchive(d)}
            title={d.is_active ? "Archive" : "Restore"}
          >
            {d.is_active ? <Archive /> : <RotateCcw />}
            <span className="sr-only">{d.is_active ? "Archive" : "Restore"}</span>
          </Button>
          <Button size="icon-sm" variant="ghost" onClick={() => setDeleting(d)}>
            <Trash2 />
            <span className="sr-only">Delete</span>
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div className="p-4 sm:p-8">
      <PageHeader
        title="Departments"
        subtitle="Manage the departments employees are assigned to."
        actions={
          <Button onClick={openCreate}>
            <Plus />
            Add Department
          </Button>
        }
      />

      <div className="mt-8">
        {isLoading ? (
          <p className="text-sm text-muted-foreground">Loading departments…</p>
        ) : isError ? (
          <p className="text-sm text-destructive">
            Could not load departments. Please refresh.
          </p>
        ) : (
          <DataTable
            columns={columns}
            rows={departments ?? []}
            rowKey={(d) => d.id}
            empty="No departments yet. Add your first one."
          />
        )}
      </div>

      <DepartmentFormDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        department={editing}
      />

      {/*
       * Departments are soft-deleted, so the copy deliberately avoids the
       * "cannot be undone" wording the other deletes use.
       */}
      <DeleteConfirmDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete department"
        entityName={deleting?.name}
        description="This department will be removed from the list. It can be restored later."
        onConfirm={() => performDelete(deleting!)}
      />
    </div>
  );
}
