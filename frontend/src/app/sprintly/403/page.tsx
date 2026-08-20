"use client";

import Link from "next/link";
import { Lock } from "lucide-react";
import { Button, Card } from "@/core/ui";

/**
 * Access-denied ("403 Forbidden") screen for the Projects module. Shown when a user
 * reaches a page or resource they aren't allowed to see — a member opening the
 * workspace detail view, or anyone requesting a project/workspace they aren't a
 * member of (the API answers 403 and the view redirects here).
 */
export default function AccessDeniedPage() {
  return (
    <div className="flex items-center justify-center px-4 py-6 sm:px-6 lg:px-8">
      <Card className="w-full max-w-md p-10 text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-danger/10">
          <Lock className="h-7 w-7 text-danger" />
        </div>
        <h1 className="mt-6 text-2xl font-bold text-ink">Access denied</h1>
        <p className="mt-2 text-sm text-ink-soft">
          You don&apos;t have permission to view this page or content. If you
          think this is a mistake, contact your project manager or an admin.
        </p>
        <div className="mt-6 flex items-center justify-center gap-3">
          <Button asChild>
            <Link href="/sprintly">Go to dashboard</Link>
          </Button>
          <Button variant="outline" asChild>
            <Link href="/sprintly/projects">My projects</Link>
          </Button>
        </div>
      </Card>
    </div>
  );
}
