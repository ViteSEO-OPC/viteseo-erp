import type { ReactNode } from "react";
import Link from "next/link";

import { ThemeToggle } from "@/core/components/theme-toggle";

/**
 * Shell for the public legal pages — privacy policy, terms, data deletion.
 *
 * PUBLIC ON PURPOSE, AND DELIBERATELY NOT WRAPPED IN RequireAuth. These three URLs are given to
 * Meta and are opened by people who have no account here: a reviewer checking the app, or a person
 * who messaged the Page on Instagram and wants their data removed. A login wall would make them
 * useless for both, so the pages read no authenticated state and render entirely from static text.
 *
 * Route group `(legal)` keeps the URLs flat — /privacy-policy, /terms, /data-deletion — which
 * matters because the privacy URL in particular gets pasted into Meta's App Settings and is then
 * not ours to tidy.
 *
 * Styling reuses the app's existing tokens (bg-muted / bg-card / border-border, the Exo 2 face from
 * the root layout) so these look like the product rather than a bolt-on, without redesigning
 * anything.
 */
export default function LegalLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-screen bg-muted">
            <header className="border-b border-border bg-card">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                    <Link href="/" className="text-sm font-semibold tracking-tight text-card-foreground">
                        Vite SEO Systems
                    </Link>
                    <ThemeToggle />
                </div>
            </header>

            <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
                <article className="rounded-card border border-border bg-card p-6 sm:p-10">{children}</article>

                <nav aria-label="Legal pages" className="mt-6 flex flex-wrap gap-x-5 gap-y-2 px-1 text-sm">
                    <Link href="/privacy-policy" className="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Privacy Policy
                    </Link>
                    <Link href="/terms" className="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Terms of Service
                    </Link>
                    <Link href="/data-deletion" className="text-muted-foreground underline underline-offset-4 hover:text-foreground">
                        Data Deletion
                    </Link>
                </nav>
            </main>
        </div>
    );
}
