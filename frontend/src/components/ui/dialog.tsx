"use client";

import * as React from "react";
import { Dialog as DialogPrimitive } from "radix-ui";
import { X } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * The controlled `open` prop, so `DialogContent` can tell "closing" from "closed".
 * `undefined` means an uncontrolled dialog, where freezing is skipped.
 */
const DialogOpenContext = React.createContext<boolean | undefined>(undefined);

function Dialog({
  open,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Root>) {
  return (
    <DialogOpenContext.Provider value={open}>
      <DialogPrimitive.Root open={open} {...props} />
    </DialogOpenContext.Provider>
  );
}

const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;

function DialogContent({
  className,
  children,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Content>) {
  const open = React.useContext(DialogOpenContext);

  /*
    Hold the last children rendered while open, and keep showing them through the exit
    animation.

    Callers almost always drive `open` off the very state that feeds the body
    (`open={payslipId != null}` beside `useHrPayslip(payslipId)`). Closing clears that state,
    so on the closing render the body has already collapsed to its "Loading…" or empty branch
    — but Radix keeps the content mounted for ~150ms to fade it out. The result is a
    full-size modal snapping down to a small placeholder box and only then disappearing.

    `DeleteConfirmDialog` solves its own case by snapshotting on open, which works only
    because its copy arrives as static props. That cannot help a dialog whose content
    streams in from a query after opening, so the freeze belongs here instead.
  */
  /*
    The ref is read and written during render, which `react-hooks/refs` normally forbids.
    It is the right tool here and the alternatives are worse:

      - Snapshotting into state on the open→false transition captures nothing, because by that
        render the caller has already cleared the state and `children` is the collapsed branch.
        The good copy only exists in the render BEFORE the close.
      - Mirroring `children` into state while open costs an extra render per parent render,
        for every mounted dialog, since `children` is a fresh object each time.

    The write is idempotent (same value on a StrictMode double-render) and nothing here feeds
    layout or effects, so a discarded render cannot leave the ref in a state anyone observes.
  */
  /* eslint-disable react-hooks/refs -- see above; caching the last open children is the point */
  const lastOpenChildren = React.useRef(children);
  if (open !== false) {
    lastOpenChildren.current = children;
  }
  const body = open === false ? lastOpenChildren.current : children;
  /* eslint-enable react-hooks/refs */

  return (
    <DialogPrimitive.Portal>
      <DialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/40 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
      <DialogPrimitive.Content
        className={cn(
          "fixed top-1/2 left-1/2 z-50 w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-card border border-border bg-card p-6 shadow-lg focus:outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
          className,
        )}
        {...props}
      >
        {body}
        <DialogPrimitive.Close className="absolute top-4 right-4 rounded-md text-muted-foreground opacity-70 transition-opacity hover:opacity-100 focus:outline-none">
          <X className="size-4" />
          <span className="sr-only">Close</span>
        </DialogPrimitive.Close>
      </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
  );
}

function DialogHeader({ className, ...props }: React.ComponentProps<"div">) {
  return <div className={cn("mb-4 space-y-1", className)} {...props} />;
}

function DialogFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      className={cn("mt-6 flex justify-end gap-2", className)}
      {...props}
    />
  );
}

function DialogTitle({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Title>) {
  return (
    <DialogPrimitive.Title
      className={cn("text-h3 font-bold text-foreground", className)}
      {...props}
    />
  );
}

function DialogDescription({
  className,
  ...props
}: React.ComponentProps<typeof DialogPrimitive.Description>) {
  return (
    <DialogPrimitive.Description
      className={cn("text-caption text-muted-foreground", className)}
      {...props}
    />
  );
}

export {
  Dialog,
  DialogTrigger,
  DialogClose,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
};
