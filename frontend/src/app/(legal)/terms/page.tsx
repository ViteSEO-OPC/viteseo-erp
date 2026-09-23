import type { Metadata } from "next";

export const metadata: Metadata = {
    title: "Terms of Service — Vite SEO Systems",
    description: "Terms governing use of Vite SEO Systems, operated by Vite SEO Digital Advertising OPC.",
};

const EFFECTIVE = "23 September 2026";
const CONTACT = "julius.santiago@viteseo.ph";

/**
 * Public terms of service.
 *
 * Kept general on purpose. No uptime figure, no warranty, no named governing jurisdiction and no
 * dispute-resolution venue appear here, because none of those has been decided or documented by the
 * business — and a term invented to fill a heading is worse than an absent one, since it reads as a
 * commitment the company never made.
 */
export default function TermsPage() {
    return (
        <>
            <header className="mb-8">
                <h1 className="text-2xl font-semibold tracking-tight text-card-foreground sm:text-3xl">Terms of Service</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Vite SEO Digital Advertising OPC · Effective {EFFECTIVE}
                </p>
            </header>

            <div className="space-y-8 text-sm leading-relaxed text-card-foreground">
                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">1. Acceptance</h2>
                    <p>
                        These terms govern access to and use of Vite SEO Systems (the &ldquo;platform&rdquo;),
                        operated by Vite SEO Digital Advertising OPC (&ldquo;Vite SEO&rdquo;, &ldquo;we&rdquo;). By
                        using the platform, or by contacting us through a messaging channel connected to it, you
                        agree to these terms. If you do not agree, please do not use the platform.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">2. Who the platform is for</h2>
                    <p>
                        The platform is an internal business system for Vite SEO staff and authorised users. Accounts
                        are issued by us; they are personal to the user they are issued to and must not be shared.
                        You are responsible for activity carried out under your account and for keeping your
                        credentials confidential.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">3. Permitted use</h2>
                    <p>
                        You may use the platform for the legitimate business purposes for which access was granted.
                        You agree not to use it in a way that breaks the law, infringes the rights of others, or
                        interferes with its operation or security.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">4. Communications</h2>
                    <p>
                        Messages you send to a Facebook Page or Instagram account we operate are received by the
                        platform and may be read and responded to by authorised staff. How that information is
                        handled is described in our{" "}
                        <a href="/privacy-policy" className="underline underline-offset-4">
                            Privacy Policy
                        </a>
                        . Please do not send sensitive information — such as passwords, card numbers or government
                        identification — through a messaging channel.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">5. Third-party platforms</h2>
                    <p>
                        Facebook, Instagram and Meta are third-party platforms operated by Meta Platforms, Inc. They
                        are not owned or controlled by Vite SEO. Your use of them is governed by their own terms and
                        privacy policies, and their availability, behaviour and delivery of messages are outside our
                        control. We may change, add or remove connected channels at any time.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">6. Intellectual property</h2>
                    <p>
                        The platform, including its software, design and content, belongs to Vite SEO or its
                        licensors. These terms do not transfer any ownership to you. You may not copy, modify,
                        reverse engineer, resell or create derivative works from the platform except where the law
                        expressly permits it.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">7. Availability</h2>
                    <p>
                        We aim to keep the platform available and working, but it may be unavailable from time to
                        time — for maintenance, for updates, or because of events outside our control. We do not
                        commit to any particular level of availability, and we may modify or discontinue features.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">8. Prohibited misuse</h2>
                    <p>You agree not to:</p>
                    <ul className="list-disc space-y-1.5 pl-5">
                        <li>attempt to gain unauthorised access to the platform, another account, or any connected system;</li>
                        <li>probe, scan or test the platform&rsquo;s security without our written permission;</li>
                        <li>interfere with or disrupt the platform, including by overloading it;</li>
                        <li>send malicious code, spam, or abusive, harassing or unlawful content through a connected channel;</li>
                        <li>use the platform to impersonate another person or misrepresent your affiliation;</li>
                        <li>extract data from the platform other than through features provided to you.</li>
                    </ul>
                    <p>We may suspend or withdraw access where we reasonably believe these terms have been breached.</p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">9. Disclaimers and limitations</h2>
                    <p>
                        The platform is provided on an &ldquo;as is&rdquo; and &ldquo;as available&rdquo; basis. To
                        the fullest extent permitted by applicable law, we exclude implied warranties and are not
                        liable for indirect or consequential loss arising from use of the platform. Nothing in these
                        terms limits liability that cannot lawfully be limited.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">10. Changes</h2>
                    <p>
                        We may update these terms as the platform develops. When we do, we will revise the effective
                        date at the top of this page. Continuing to use the platform after a change takes effect
                        means you accept the updated terms.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">11. Contact</h2>
                    <p>
                        Questions about these terms:{" "}
                        <a href={`mailto:${CONTACT}`} className="underline underline-offset-4">
                            {CONTACT}
                        </a>
                    </p>
                </section>
            </div>
        </>
    );
}
