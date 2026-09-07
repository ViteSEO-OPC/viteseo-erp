"use client";

import { useState, type ReactNode } from "react";

import { ConfirmDialog } from "@/core/ui/confirm-dialog";

interface ConfirmOptions {
  title: string;
  description?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  onConfirm: () => void;
}

/**
 * Imperative confirmation dialog. Call `confirm({ title, description, onConfirm })`
 * anywhere (a drop-in for window.confirm) and render `{confirmDialog}` once in the
 * component. The dialog closes and runs `onConfirm` when the user confirms.
 */
export function useConfirm() {
  const [opts, setOpts] = useState<ConfirmOptions | null>(null);

  const confirmDialog = (
    <ConfirmDialog
      open={opts !== null}
      onClose={() => setOpts(null)}
      onConfirm={() => {
        opts?.onConfirm();
        setOpts(null);
      }}
      title={opts?.title ?? ""}
      description={opts?.description}
      confirmLabel={opts?.confirmLabel ?? "Confirm"}
      cancelLabel={opts?.cancelLabel}
      destructive={opts?.destructive ?? true}
    />
  );

  return { confirm: (o: ConfirmOptions) => setOpts(o), confirmDialog };
}
