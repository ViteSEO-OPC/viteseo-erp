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

/*
  Most of these dialogs scroll themselves (`max-h-[90svh] overflow-y-auto`), and
  an element's own scrollbar is NOT clipped by its own `border-radius`. The
  browser's default opaque track is therefore painted as a full-height rectangle
  across the modal's rounded right corners — squaring them off and breaking the
  border, which is what makes the modal look lopsided next to its own left side.

  Fixed by styling rather than restructuring: a transparent track removes the
  rectangle entirely, and the thumb becomes a pill held off the edge by a
  transparent border with `background-clip: padding-box`. The rounded corners and
  the border are then never painted over, so the modal keeps its shape whether it
  scrolls or not. Both syntaxes are needed — the standard properties for Firefox,
  the `::-webkit-` pseudo-elements for Chrome, Edge and Safari.

  Applied once here so it covers every dialog in the app; the alternative was
  moving the scroll onto an inner wrapper in ~46 callers, which changes each
  one's layout for a purely cosmetic defect.
*/
export const dialogScrollbar = [
  "[scrollbar-width:thin] [scrollbar-color:var(--border)_transparent]",
  "[&::-webkit-scrollbar]:w-2.5",
  "[&::-webkit-scrollbar-track]:bg-transparent",
  "[&::-webkit-scrollbar-thumb]:rounded-full",
  "[&::-webkit-scrollbar-thumb]:border-[3px] [&::-webkit-scrollbar-thumb]:border-transparent",
  "[&::-webkit-scrollbar-thumb]:bg-border [&::-webkit-scrollbar-thumb]:bg-clip-padding",
  "[&::-webkit-scrollbar-thumb:hover]:bg-muted-foreground [&::-webkit-scrollbar-thumb:hover]:bg-clip-padding",
].join(" ");

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
          /*
            Centred on the content area, not the viewport: from `xl` up the shell
            puts a static 16rem sidebar on the left (`TeamoraShell` → core
            `Sidebar`, `w-64`), so a viewport-centred modal reads as sitting
            left of where the user is looking. `xl:left-64` moves the positioning
            box to start after the sidebar; below `xl` the sidebar is an
            off-canvas drawer and the box is the full width.

            This breakpoint is `TeamoraShell`'s, not a choice of its own — if the
            shell's changes, this has to change with it or modals will offset
            around a sidebar that isn't on screen.

            `inset-x-0 + mx-auto` rather than `left-1/2 + -translate-x-1/2`:
            bounded on both sides, the auto margins split the slack evenly, so
            the modal centres inside that region for any width — no per-modal
            offset to keep in sync with the sidebar.
          */
          "fixed inset-x-0 top-1/2 z-50 mx-auto w-full max-w-md -translate-y-1/2 rounded-card border border-border bg-card p-6 shadow-lg focus:outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 xl:left-64",
          dialogScrollbar,
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
