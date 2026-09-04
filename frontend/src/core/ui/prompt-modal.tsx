"use client";

import { useEffect, useState } from "react";

import { Modal } from "@/core/ui/modal";

export interface PromptModalProps {
  open: boolean;
  title: string;
  label: string;
  initialValue?: string;
  type?: "text" | "number";
  placeholder?: string;
  confirmLabel?: string;
  pending?: boolean;
  /** Optional helper/validation text shown under the input. */
  hint?: string;
  onConfirm: (value: string) => void;
  onClose: () => void;
}

const inputCls =
  "h-10 w-full rounded-lg border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

/**
 * A single-input modal — a proper replacement for window.prompt(). Handles
 * Enter-to-confirm and resets to `initialValue` each time it opens.
 */
export function PromptModal({
  open,
  title,
  label,
  initialValue = "",
  type = "text",
  placeholder,
  confirmLabel = "Save",
  pending = false,
  hint,
  onConfirm,
  onClose,
}: PromptModalProps) {
  const [value, setValue] = useState(initialValue);

  useEffect(() => {
    if (open) setValue(initialValue);
  }, [open, initialValue]);

  const submit = () => {
    if (value.trim() === "") return;
    onConfirm(value.trim());
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      footer={
        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted/40"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={pending || value.trim() === ""}
            onClick={submit}
            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-foreground hover:opacity-90 disabled:opacity-50"
          >
            {pending ? "Saving…" : confirmLabel}
          </button>
        </div>
      }
    >
      <label className="grid gap-1.5">
        <span className="text-sm font-medium text-foreground">{label}</span>
        <input
          autoFocus
          type={type}
          inputMode={type === "number" ? "decimal" : undefined}
          step={type === "number" ? "0.01" : undefined}
          value={value}
          placeholder={placeholder}
          onChange={(e) => setValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") submit();
          }}
          className={inputCls}
        />
        {hint ? <span className="text-caption text-muted-foreground">{hint}</span> : null}
      </label>
    </Modal>
  );
}
