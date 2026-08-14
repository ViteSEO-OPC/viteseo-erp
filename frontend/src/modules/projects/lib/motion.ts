/**
 * Open animations for the module's hand-rolled overlays.
 *
 * Most PMS modals go through `core/ui` → `Modal` → Radix `DialogContent`, which
 * already animates: the overlay fades, the panel fades and scales up from 95%.
 * The Import Tasks modal is one of those, which is why it felt different from
 * the surfaces that build their own overlay out of plain divs — those appeared
 * instantly, with no transition at all.
 *
 * These constants are that same animation, spelled out for markup Radix does
 * not own, at 150ms rather than the shared Dialog's 200ms. A modal you open
 * dozens of times an hour wants to feel immediate, and the shorter window still
 * reads as a movement rather than a jump.
 *
 * NOTE: `core/ui/dialog.tsx` is still on `duration-200`, and it is platform
 * code — HR and CRM open their modals through it too — so the PMS modals built
 * on the shared `Modal` remain 200ms. Bringing the two families back together
 * means changing that one class in core, which affects all three modules.
 *
 * Enter only. Radix animates the exit off `data-[state=closed]`, which needs a
 * presence machine to hold the node in the tree while it plays; these overlays
 * unmount the moment `open` flips, so there is nothing left to animate out.
 */

/*
 * Every string below is written out in full, `duration-150` included. Tailwind
 * finds classes by scanning source text, so a shared `${DURATION}` constant —
 * tidier as it looks — is the first step toward a class it cannot see.
 */

/** The dimmed backdrop behind any overlay. */
export const OVERLAY_ENTER = "animate-in fade-in-0 duration-100";

/** A centred modal panel — the shared Dialog's motion, quicker. */
export const MODAL_PANEL_ENTER = "animate-in fade-in-0 zoom-in-95 duration-100";

/**
 * A panel anchored to an edge. Zooming a full-height drawer from its centre
 * reads as a wobble, so the same fade and duration carry a slide from the edge
 * the panel is attached to instead.
 */
export const SHEET_FROM_BOTTOM_ENTER =
  "animate-in fade-in-0 slide-in-from-bottom-4 duration-100";
export const SHEET_FROM_RIGHT_ENTER =
  "animate-in fade-in-0 slide-in-from-right-4 duration-100";
