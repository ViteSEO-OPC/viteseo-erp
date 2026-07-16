'use client';

import * as React from 'react';

import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/core/ui/dialog';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
  width?: number;
}

/**
 * Convenience modal built on the shadcn/Radix Dialog, preserving the original
 * `{ open, onClose, title, children, footer, width }` API. Radix handles focus
 * trapping, scroll locking, Escape-to-close, and overlay click-to-close.
 */
export function Modal({ open, onClose, title, children, footer, width = 520 }: ModalProps) {
  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent
        style={{ width: '100%', maxWidth: width }}
        className="max-h-[90vh] gap-0 overflow-y-auto rounded-2xl bg-surface p-0 sm:max-w-[calc(100%-2rem)]"
      >
        <DialogHeader className="space-y-0 border-b border-line px-6 py-4 text-left">
          <DialogTitle className="text-lg font-bold text-ink">{title}</DialogTitle>
        </DialogHeader>
        <div className="px-6 py-5">{children}</div>
        {footer && (
          <DialogFooter className="border-t border-line px-6 py-4">{footer}</DialogFooter>
        )}
      </DialogContent>
    </Dialog>
  );
}
