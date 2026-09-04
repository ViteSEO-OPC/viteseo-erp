"use client";

import { useCallback, useRef, useState } from "react";

/**
 * Legacy copy path for origins where the async Clipboard API does not exist.
 *
 * `navigator.clipboard` is gated behind a SECURE CONTEXT. `localhost` gets an exemption even over
 * plain http, but a LAN IP (http://192.168.x.x:3000 — a supported dev origin here, see
 * `allowedDevOrigins` in next.config.ts) does not, so the browser omits the interface entirely and
 * `navigator.clipboard.writeText` throws a TypeError on property access rather than rejecting.
 *
 * `document.execCommand("copy")` is deprecated but still implemented everywhere and, unlike the
 * Clipboard API, works in an insecure context. It is SYNCHRONOUS and must run inside the user's
 * click — which is why the caller feature-detects before awaiting anything.
 */
function copyViaTextarea(text: string): boolean {
  if (typeof document === "undefined") {
    return false;
  }

  const textarea = document.createElement("textarea");
  textarea.value = text;
  // Off-screen but still selectable: `display:none`/`visibility:hidden` would make the selection
  // fail. readonly stops the mobile keyboard opening; the fixed position avoids a scroll jump.
  textarea.setAttribute("readonly", "");
  textarea.setAttribute("aria-hidden", "true");
  textarea.style.position = "fixed";
  textarea.style.top = "0";
  textarea.style.left = "0";
  textarea.style.opacity = "0";
  textarea.style.pointerEvents = "none";
  document.body.appendChild(textarea);

  // Preserve whatever the user had selected — copying must not clobber their selection.
  const selection = document.getSelection();
  const previousRange = selection && selection.rangeCount > 0 ? selection.getRangeAt(0) : null;

  let succeeded = false;
  try {
    textarea.select();
    textarea.setSelectionRange(0, text.length); // iOS Safari ignores select() without an explicit range
    succeeded = document.execCommand("copy");
  } catch {
    succeeded = false;
  } finally {
    document.body.removeChild(textarea);
    if (selection && previousRange) {
      selection.removeAllRanges();
      selection.addRange(previousRange);
    }
  }

  return succeeded;
}

/**
 * Clipboard helper shared by the CRM (Team Accounts credentials, Outreach composer Copy).
 * Returns `copied` (auto-resets after ~2s) and a `copy(text)` that resolves to whether the write
 * succeeded through EITHER mechanism — modern Clipboard API first, legacy textarea fallback second.
 * It resolves false only when both fail, so callers can surface an honest failure.
 */
export function useCopyToClipboard() {
  const [copied, setCopied] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const copy = useCallback(async (text: string): Promise<boolean> => {
    let succeeded = false;

    // Feature-detect BEFORE awaiting. On an insecure origin the interface is absent, so nothing is
    // awaited and the fallback below still runs inside the original user gesture — which is the
    // condition execCommand requires.
    if (typeof navigator !== "undefined" && typeof navigator.clipboard?.writeText === "function") {
      try {
        await navigator.clipboard.writeText(text);
        succeeded = true;
      } catch {
        succeeded = false; // present but refused (denied permission, unfocused document)
      }
    }

    if (!succeeded) {
      succeeded = copyViaTextarea(text);
    }

    if (succeeded) {
      setCopied(true);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => setCopied(false), 2000);
    }

    return succeeded;
  }, []);

  return { copied, copy };
}
