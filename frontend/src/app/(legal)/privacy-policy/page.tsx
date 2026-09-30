import type { Metadata } from "next";

export const metadata: Metadata = {
    title: "Privacy Policy — Vite SEO Systems",
    description: "How Vite SEO Digital Advertising OPC handles information received through Vite SEO Systems.",
};

const EFFECTIVE = "23 September 2026";
const CONTACT = "julius.santiago@viteseo.ph";

/**
 * Public privacy policy.
 *
 * WRITTEN FROM THE SYSTEM AS IT ACTUALLY IS, not from what it might become. Every claim below was
 * checked against the deployed code: the inbound integration holds no Page Access Token, makes no
 * Graph API call, sends nothing to any AI provider, and creates no lead by itself. Saying otherwise
 * would be the kind of inaccuracy that costs an App Review and, worse, misleads the people whose
 * messages we hold.
 *
 * Deliberately absent: fixed retention periods, certifications, a named data-protection officer,
 * a postal address and a governing jurisdiction. None of those are documented anywhere in this
 * business yet, and inventing them here would make this page a liability rather than a disclosure.
 */
export default function PrivacyPolicyPage() {
    return (
        <>
            <header className="mb-8">
                <h1 className="text-2xl font-semibold tracking-tight text-card-foreground sm:text-3xl">Privacy Policy</h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Vite SEO Digital Advertising OPC · Effective {EFFECTIVE}
                </p>
            </header>

            <div className="space-y-8 text-sm leading-relaxed text-card-foreground">
                <section className="space-y-3">
                    <p>
                        Vite SEO Digital Advertising OPC (&ldquo;Vite SEO&rdquo;, &ldquo;we&rdquo;) operates Vite SEO
                        Systems, an internal business platform used by our staff to manage client relationships,
                        projects and people. This policy explains what information the platform processes when
                        someone contacts us through a messaging channel we have connected to it, and what we do with
                        that information.
                    </p>
                    <p>
                        It covers the platform at <span className="font-medium">system.viteseo.ph</span>. It does not
                        cover Facebook, Instagram or any other third-party service, each of which applies its own
                        privacy policy to the data it holds.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Information we receive</h2>
                    <p>
                        When you send a message to a Facebook Page or Instagram account we operate, Meta forwards
                        that message to our platform. Depending on what you send, this may include:
                    </p>
                    <ul className="list-disc space-y-1.5 pl-5">
                        <li>the content of your message, including text you write;</li>
                        <li>attachments you send, or information describing them;</li>
                        <li>
                            the identifiers the platform assigns to you for our Page or account — these are
                            platform-scoped identifiers, not your login details;
                        </li>
                        <li>the identifier of the Page or Instagram account you messaged;</li>
                        <li>conversation and message identifiers used to keep a thread in order;</li>
                        <li>the time the message was sent and the time we received it;</li>
                        <li>
                            event metadata that accompanies the message, and the technical information we use to
                            confirm a message genuinely came from Meta before we accept it.
                        </li>
                    </ul>
                    <p>
                        We do not ask Meta for your profile, your friends, your posts or any data beyond the message
                        events described above, and the platform makes no request to Meta for additional information
                        about you.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Why we process it</h2>
                    <ul className="list-disc space-y-1.5 pl-5">
                        <li>to receive and read messages customers send us;</li>
                        <li>to organise Messenger and Instagram conversations so they can be followed up;</li>
                        <li>to let authorised Vite SEO staff manage and respond to those conversations;</li>
                        <li>to keep a history of a conversation so context is not lost between staff;</li>
                        <li>to protect the security and integrity of the platform, including rejecting forged or malformed requests;</li>
                        <li>to operate and troubleshoot the service.</li>
                    </ul>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">What we do not do</h2>
                    <p>So that there is no ambiguity about the current system:</p>
                    <ul className="list-disc space-y-1.5 pl-5">
                        <li>We do not use Meta AI, and message content is not processed by Meta&rsquo;s AI features through this platform.</li>
                        <li>
                            We do not send message content to any external AI or language-model provider. No such
                            integration is enabled.
                        </li>
                        <li>An incoming message does not by itself create a sales record about you.</li>
                        <li>We do not sell your information, and we do not use it for advertising profiling.</li>
                        <li>The inbound system holds no access token for your account and makes no calls to Meta&rsquo;s platform on your behalf.</li>
                    </ul>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Who can see it</h2>
                    <p>
                        Access is limited to authorised Vite SEO staff who need it to do their work. Each
                        organisation&rsquo;s data is kept separate within the platform, and a conversation is visible
                        only to the organisation whose Page or account received it.
                    </p>
                    <p>
                        We rely on service providers to run the platform — hosting, infrastructure and delivery
                        providers — who process data on our instructions to the extent needed to provide those
                        services. We may also disclose information where we are legally required to do so, or where
                        it is necessary to protect our rights, our staff or the security of the service.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Security</h2>
                    <p>
                        Messages reach the platform over an encrypted connection, and every incoming message is
                        cryptographically verified as genuinely originating from Meta before it is accepted; anything
                        that fails that check is rejected and stored nowhere. Access to the platform requires an
                        account, and staff permissions are limited by role. Message content is never written to our
                        operational logs. No system can be guaranteed absolutely secure, but these controls are
                        designed to keep the risk low.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">How long we keep it</h2>
                    <p>
                        We keep conversation records for as long as they are needed for the purposes described above
                        — principally, so that an ongoing or future customer relationship has its context — and for
                        as long as we are required to keep them to meet legal, accounting or security obligations.
                        When a record is no longer needed for any of those purposes, it is deleted or anonymised.
                        You can ask us to delete your information sooner; see below.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Your choices and rights</h2>
                    <p>
                        You may ask us what information we hold about you, ask us to correct it, ask us to delete it,
                        or object to how we use it. You can also stop sending us messages at any time, and you can
                        manage or remove the conversation on Facebook or Instagram using the controls those platforms
                        provide.
                    </p>
                    <p>
                        To make a request, email{" "}
                        <a href={`mailto:${CONTACT}`} className="underline underline-offset-4">
                            {CONTACT}
                        </a>
                        . For deletion specifically, our{" "}
                        <a href="/data-deletion" className="underline underline-offset-4">
                            Data Deletion Instructions
                        </a>{" "}
                        explain what to include and what happens next. We may need to verify your identity before
                        acting on a request, so that we do not disclose or delete someone else&rsquo;s information on
                        a stranger&rsquo;s say-so.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Children</h2>
                    <p>
                        The platform is a business tool and is not directed at children. We do not knowingly solicit
                        information from children through it.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Changes to this policy</h2>
                    <p>
                        We may update this policy as the platform changes. When we do, we will revise the effective
                        date at the top of this page. Material changes to how we handle messages will be reflected
                        here before they take effect.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Contact</h2>
                    <p>
                        Vite SEO Digital Advertising OPC —{" "}
                        <a href={`mailto:${CONTACT}`} className="underline underline-offset-4">
                            {CONTACT}
                        </a>
                    </p>
                </section>
            </div>
        </>
    );
}
