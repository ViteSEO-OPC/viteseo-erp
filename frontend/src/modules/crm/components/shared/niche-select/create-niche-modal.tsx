"use client";

import { useState } from "react";
import { Check, Pencil, Trash2, X } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { cn } from "@/modules/crm/lib/utils";
import {
  useNiches,
  useCreateNiche,
  useDeleteNiche,
  useRenameNiche,
  type Niche,
} from "@/modules/crm/hooks/use-niches";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

const iconButtonClass =
  "flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-40";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Fired after the niche is successfully registered in crm_niches — the parent then opens the
   *  Assign dialog (Sequence 2) with this name. */
  onCreated: (name: string) => void;
};

/**
 * Sequence 1 — Add New Niche (matches the approved sequence-1 mockup). Creates/registers a niche in
 * `crm_niches` AND lists the custom niches already created so they can be renamed or deleted. There
 * is NO lead-assignment UI here; on a successful create the parent hands off to the Assign dialog
 * (Sequence 2).
 *
 * Only CUSTOM niches are listed for management: built-ins are the approved canonical vocabulary the
 * API refuses to rename or delete, and a custom niche still assigned to leads can't be deleted
 * either. A row shows only its name until SELECTED, which reveals its edit/delete actions.
 */
export function CreateNicheModal({ open, onOpenChange, onCreated }: Props) {
  const { niches, isLoading } = useNiches(open);
  const createNiche = useCreateNiche();
  const renameNiche = useRenameNiche();
  const deleteNiche = useDeleteNiche();

  const [name, setName] = useState("");
  const [error, setError] = useState("");
  // Which custom niche is active — the only row that exposes edit/delete. One at a time.
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editingName, setEditingName] = useState("");
  // Two-step delete: the first click arms the row, the second confirms.
  const [confirmingId, setConfirmingId] = useState<string | null>(null);

  const customNiches = niches.filter((niche) => niche.isCustom);

  const reset = () => {
    setName("");
    setError("");
    setSelectedId(null);
    setEditingId(null);
    setEditingName("");
    setConfirmingId(null);
  };

  const close = () => {
    reset();
    onOpenChange(false);
  };

  const handleSave = () => {
    const trimmed = name.trim();
    if (!trimmed) {
      setError("Enter a niche name.");
      return;
    }
    setError("");
    createNiche.mutate(trimmed, {
      onSuccess: (niche) => {
        reset();
        onCreated(niche.name);
      },
      // The server owns duplicate detection (case-insensitively); show its message inline.
      onError: (err) => {
        setError(
          (err as { response?: { data?: { message?: string } } }).response?.data?.message ??
            "Failed to create business niche.",
        );
      },
    });
  };

  // Selecting a row activates it (revealing its actions); clicking the active row again collapses it.
  const selectRow = (niche: Niche) => {
    setEditingId(null);
    setConfirmingId(null);
    setSelectedId((prev) => (prev === niche.id ? null : niche.id));
  };

  const startEditing = (niche: Niche) => {
    setConfirmingId(null);
    setEditingId(niche.id);
    setEditingName(niche.name);
  };

  const commitRename = (niche: Niche) => {
    const trimmed = editingName.trim();
    if (!trimmed || trimmed === niche.name) {
      setEditingId(null);
      return;
    }
    renameNiche.mutate(
      { id: niche.id, name: trimmed },
      { onSuccess: () => setEditingId(null) },
    );
  };

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) close(); }}>
      <DialogContent
        showCloseButton={false}
        /* z-[60] on content + backdrop so this stacks above the Create/Edit-Lead form dialog it is
           opened from (that dialog is z-50). */
        className="z-[60] flex h-[648px] max-h-[85dvh] w-full flex-col gap-0 overflow-hidden rounded-2xl p-0 shadow-xl sm:max-w-[520px]"
        overlayClassName="z-[60]"
      >
        {/* FIXED header */}
        <DialogHeader className="shrink-0 px-6 pt-6 pb-0">
          <div className="flex items-start justify-between gap-4">
            <div className="flex flex-col gap-1.5">
              <DialogTitle className="text-xl font-bold text-card-foreground">
                Add New Niche
              </DialogTitle>
              <DialogDescription>
                Create a niche (category) that can be assigned to leads and used in the niche filter.
              </DialogDescription>
            </div>
            <button type="button" onClick={close} aria-label="Close" className={closeButtonClass}>
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        {/* FIXED: create input (stationary) */}
        <div className="shrink-0 px-6 pt-5">
          <FormField label="Niche Name" required>
            <Input
              id="create-niche-name"
              value={name}
              onChange={(e) => { setName(e.target.value); setError(""); }}
              onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); handleSave(); } }}
              placeholder="e.g. Automotive"
              aria-label="Niche Name"
              aria-invalid={error ? true : undefined}
              className="h-10 w-full px-3.5"
              autoFocus
            />
            {error ? <p className="mt-1.5 text-sm text-red-500">{error}</p> : null}
          </FormField>
        </div>

        {/* FIXED: divider + Custom niches heading + description (stationary above the scroll) */}
        <div className="shrink-0 px-6 pt-5">
          <div className="border-t border-border-subtle pt-4">
            <h3 className="text-base font-semibold text-card-foreground">Custom niches</h3>
            <p className="mt-1 text-sm text-muted-foreground">
              Rename or delete niches you&rsquo;ve created. Built-in niches and niches still assigned
              to leads can&rsquo;t be deleted.
            </p>
          </div>
        </div>

        {/* SCROLLABLE: only the niche list scrolls (~5 items visible). With the dialog at a fixed
            height, adding more niches scrolls the list instead of growing the modal. */}
        <div className="min-h-0 flex-1 overflow-y-auto px-6 pt-3 pb-4">
          {isLoading ? (
            <p className="py-2 text-sm text-muted-foreground">Loading&hellip;</p>
          ) : customNiches.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              No custom niches yet — create one above. Built-in niches can&rsquo;t be edited or
              removed.
            </p>
          ) : (
            <ul className="flex flex-col gap-2">
                {customNiches.map((niche) => {
                  const inUse = niche.leadCount > 0;
                  const isActive = selectedId === niche.id;
                  const isEditing = editingId === niche.id;
                  const isConfirming = confirmingId === niche.id;
                  const isOpen = isActive || isEditing || isConfirming;

                  return (
                    <li
                      key={niche.id}
                      className={cn(
                        "flex min-h-[42px] items-center gap-1 rounded-lg border px-1.5 transition-colors",
                        isOpen
                          ? "border-teal-400 bg-muted/40"
                          : "border-border bg-background hover:bg-muted/40",
                      )}
                    >
                      {isEditing ? (
                        <>
                          <Input
                            value={editingName}
                            onChange={(e) => setEditingName(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === "Enter") { e.preventDefault(); commitRename(niche); }
                              if (e.key === "Escape") setEditingId(null);
                            }}
                            aria-label={`Rename ${niche.name}`}
                            autoFocus
                            className="h-8 flex-1"
                          />
                          <button
                            type="button"
                            onClick={() => commitRename(niche)}
                            disabled={renameNiche.isPending}
                            aria-label={`Save new name for ${niche.name}`}
                            className={iconButtonClass}
                          >
                            <Check className="h-4 w-4" />
                          </button>
                          <button
                            type="button"
                            onClick={() => setEditingId(null)}
                            aria-label="Cancel rename"
                            className={iconButtonClass}
                          >
                            <X className="h-4 w-4" />
                          </button>
                        </>
                      ) : isConfirming ? (
                        <>
                          <span className="flex-1 truncate pl-1.5 text-sm text-foreground">
                            Delete &ldquo;{niche.name}&rdquo;?
                          </span>
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={() =>
                              deleteNiche.mutate(niche.id, {
                                onSettled: () => setConfirmingId(null),
                              })
                            }
                            disabled={deleteNiche.isPending}
                          >
                            Delete
                          </Button>
                          <Button variant="outline" size="sm" onClick={() => setConfirmingId(null)}>
                            Cancel
                          </Button>
                        </>
                      ) : (
                        <>
                          <button
                            type="button"
                            onClick={() => selectRow(niche)}
                            aria-pressed={isActive}
                            className="flex min-w-0 flex-1 items-center gap-2 py-2 pl-1.5 pr-1 text-left"
                          >
                            <span className="flex-1 truncate text-sm text-foreground">{niche.name}</span>
                            {isActive && inUse ? (
                              <span className="shrink-0 text-xs text-muted-foreground">
                                {niche.leadCount} lead{niche.leadCount === 1 ? "" : "s"}
                              </span>
                            ) : null}
                          </button>
                          {isActive ? (
                            <div className="flex shrink-0 items-center gap-0.5 pr-1">
                              <button
                                type="button"
                                onClick={() => startEditing(niche)}
                                aria-label={`Rename ${niche.name}`}
                                className={iconButtonClass}
                              >
                                <Pencil className="h-4 w-4" />
                              </button>
                              <button
                                type="button"
                                onClick={() => { setEditingId(null); setConfirmingId(niche.id); }}
                                disabled={inUse}
                                aria-label={
                                  inUse
                                    ? `${niche.name} is assigned to leads and can't be deleted`
                                    : `Delete ${niche.name}`
                                }
                                title={inUse ? "Still assigned to leads — can't be deleted" : undefined}
                                className={iconButtonClass}
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </div>
                          ) : null}
                        </>
                      )}
                    </li>
                  );
                })}
              </ul>
            )}
        </div>

        {/* FIXED footer */}
        <DialogFooter className="mx-0 mb-0 shrink-0 flex-row justify-end gap-3 border-t-0 bg-transparent px-6 pb-6 pt-4">
          <Button variant="outline" size="lg" className="px-4" onClick={close} disabled={createNiche.isPending}>
            Cancel
          </Button>
          <Button
            variant="default"
            size="lg"
            className="px-4"
            onClick={handleSave}
            disabled={!name.trim() || createNiche.isPending}
          >
            {createNiche.isPending ? "Saving…" : "Save Niche"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
