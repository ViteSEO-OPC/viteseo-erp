"use client";

import { useEffect } from "react";
import Link from "next/link";
import { AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";

/**
 * The error boundary for every TeamOra screen.
 *
 * It exists because until now there was none anywhere in the app: a single component throwing
 * mid-render — one unmapped status in a report table was enough — unmounted the entire tree, nav
 * and all, leaving a blank page that read to the user as a 404. This keeps the failure inside the
 * page: the shell and navigation above it survive, and the reader is told what to do next.
 *
 * It wraps the pages under /teamora, not the layout beside it — a throw in TeamoraLayout itself
 * still escapes to the root boundary, which is what `global-error` would be for.
 */
export default function TeamoraError({
  error,
  unstable_retry,
}: {
  error: Error & { digest?: string };
  unstable_retry: () => void;
}) {
  useEffect(() => {
    // Production strips the message off server-thrown errors, so the digest is the only handle
    // support has for matching this against the server log. Print both.
    console.error("TeamOra page error", error);
  }, [error]);

  return (
    <div className="p-4 sm:p-8">
      <div className="mx-auto max-w-lg rounded-card border border-border bg-card p-8 text-center">
        <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-danger-subtle">
          <AlertTriangle className="size-6 text-danger" />
        </div>

        <h1 className="mt-4 text-h3 font-bold text-foreground">This page ran into a problem</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Nothing you were looking at was changed or lost. Try again — if it keeps happening, send
          HR the reference below.
        </p>

        {error.digest && (
          <p className="mt-3 font-mono text-caption text-muted-foreground">
            Reference: {error.digest}
          </p>
        )}

        <div className="mt-6 flex items-center justify-center gap-2">
          <Button onClick={() => unstable_retry()}>Try again</Button>
          <Button variant="outline" asChild>
            <Link href="/teamora">Back to TeamOra</Link>
          </Button>
        </div>
      </div>
    </div>
  );
}
