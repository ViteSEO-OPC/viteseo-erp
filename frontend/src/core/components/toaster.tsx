"use client";

import { Toaster as SonnerToaster } from "sonner";

// App-wide toast host. Rendered once in the root layout. Toasts are fully
// custom-rendered (Figma teal card, per-variant icon/title) via the `notify`
// helper — fire them with `import { notify as toast } from "@/core/lib/notify"`
// (toast.success / toast.error / toast.info). `unstyled` keeps sonner from
// wrapping our card in its own default chrome.
export function Toaster() {
  return (
    <SonnerToaster
      position="top-right"
      toastOptions={{ unstyled: true, classNames: { toast: "w-full flex justify-end" } }}
    />
  );
}
