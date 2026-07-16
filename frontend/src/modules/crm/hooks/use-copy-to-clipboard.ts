"use client";

import { useCallback, useRef, useState } from "react";

/**
 * Small clipboard helper for the Team Accounts flow (copy temporary password /
 * account details). Returns `copied` (auto-resets after ~2s) and a `copy(text)`
 * that resolves to whether the write succeeded.
 */
export function useCopyToClipboard() {
  const [copied, setCopied] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const copy = useCallback(async (text: string): Promise<boolean> => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => setCopied(false), 2000);
      return true;
    } catch {
      return false;
    }
  }, []);

  return { copied, copy };
}
