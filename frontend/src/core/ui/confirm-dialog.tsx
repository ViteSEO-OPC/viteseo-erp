'use client';

import * as React from 'react';

import { Button } from '@/core/ui/button';
import { Input } from '@/core/ui/input';
import { Modal } from '@/core/ui/modal';

export interface ConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  /** Body copy explaining the consequence of the action. */
  description?: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  isPending?: boolean;
  /** Red confirm button for destructive actions (default). Set false for neutral confirms. */
  destructive?: boolean;
  /**
   * When set, the user must type this exact string (e.g. the workspace name)
   * before the confirm button becomes enabled. Used for high-impact deletes.
   */
  confirmationText?: string;
}

/**
 * Destructive-action confirmation modal. For high-impact deletes (workspaces,
 * projects) pass `confirmationText` to force the user to type the entity's name
 * before the confirm button unlocks.
 */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  description,
  confirmLabel = 'Delete',
  cancelLabel = 'Cancel',
  isPending = false,
  destructive = true,
  confirmationText,
}: ConfirmDialogProps) {
  const [typed, setTyped] = React.useState('');

  // Reset the typed value whenever the dialog is (re)opened for a fresh target —
  // done during render (React's recommended pattern) rather than in an effect.
  const [wasOpen, setWasOpen] = React.useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) setTyped('');
  }

  const requiresTyping = Boolean(confirmationText);
  const matches = !requiresTyping || typed.trim() === confirmationText;
  const disabled = isPending || !matches;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      footer={
        <>
          <div className='flex justify-between w-full'>
            <Button variant="outline" type="button" onClick={onClose} disabled={isPending}>
              {cancelLabel}
            </Button>
            <Button
              variant={destructive ? 'destructive' : 'default'}
              type="button"
              onClick={onConfirm}
              disabled={disabled}
            >
              {isPending ? (destructive ? 'Deleting…' : 'Saving…') : confirmLabel}
            </Button>
          </div>
        </>
      }
    >
      <div className="space-y-4">
        {description && <div className="text-sm text-ink-soft">{description}</div>}

        {requiresTyping && (
          <div className="space-y-2">
            <p className="text-sm text-ink-soft">
              Type <span className="font-bold text-ink">{confirmationText}</span> to confirm.
            </p>
            <Input
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              placeholder={confirmationText}
              autoFocus
              onKeyDown={(e) => {
                if (e.key === 'Enter' && !disabled) onConfirm();
              }}
            />
          </div>
        )}
      </div>
    </Modal>
  );
}
