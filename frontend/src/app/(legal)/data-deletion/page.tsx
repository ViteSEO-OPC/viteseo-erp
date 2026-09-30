import type { Metadata } from "next";

export const metadata: Metadata = {
    title: "Data Deletion Instructions — Vite SEO Systems",
    description: "How to ask Vite SEO Digital Advertising OPC to delete information received through Messenger or Instagram.",
};

const EFFECTIVE = "23 September 2026";
const CONTACT = "julius.santiago@viteseo.ph";

/**
 * Public data-deletion instructions.
 *
 * INSTRUCTIONS ONLY, AND DELIBERATELY SO. Meta accepts either a callback endpoint or a public
 * instructions page, and an endpoint here would mean an unauthenticated URL capable of destroying a
 * customer's conversation history on request. Anyone who learned a platform identifier could fire
 * it, and the damage would be silent and irreversible. A reviewed human request costs a day and
 * cannot be weaponised; that trade is worth making.
 *
 * The verification step exists for the same reason in the other direction: acting on an unverified
 * request would let a stranger delete — or learn of the existence of — someone else's messages.
 */
export default function DataDeletionPage() {
    return (
        <>
            <header className="mb-8">
                <h1 className="text-2xl font-semibold tracking-tight text-card-foreground sm:text-3xl">
                    Data Deletion Instructions
                </h1>
                <p className="mt-2 text-sm text-muted-foreground">
                    Vite SEO Digital Advertising OPC · Effective {EFFECTIVE}
                </p>
            </header>

            <div className="space-y-8 text-sm leading-relaxed text-card-foreground">
                <section className="space-y-3">
                    <p>
                        If you have messaged one of our Facebook Pages or Instagram accounts, we may hold a record of
                        that conversation. You can ask us to delete it. This page explains how to make that request
                        and what happens afterwards.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">How to request deletion</h2>
                    <p>
                        Send an email to{" "}
                        <a href={`mailto:${CONTACT}?subject=Data%20deletion%20request`} className="underline underline-offset-4">
                            {CONTACT}
                        </a>{" "}
                        with the subject <span className="font-medium">Data deletion request</span>.
                    </p>
                    <p>So that we can find the right records, please tell us:</p>
                    <ul className="list-disc space-y-1.5 pl-5">
                        <li>which platform you used — Facebook Messenger or Instagram;</li>
                        <li>the Facebook Page or Instagram account you messaged;</li>
                        <li>the name or username you messaged us from;</li>
                        <li>roughly when you last messaged us;</li>
                        <li>whether you want everything deleted, or only part of it.</li>
                    </ul>
                    <p>
                        Please do not include passwords, card numbers or government identification in your email. We
                        never need them to process a deletion request.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Verifying it is you</h2>
                    <p>
                        Before we delete anything, we need to be reasonably satisfied that the request comes from the
                        person whose information it is. We may ask you to confirm the request from the same Facebook
                        or Instagram account that sent the messages, or to confirm details only you would know.
                    </p>
                    <p>
                        This step protects you: acting on an unverified request would allow someone else to erase
                        your conversation, or to learn that it exists at all.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">What happens next</h2>
                    <ol className="list-decimal space-y-1.5 pl-5">
                        <li>We acknowledge your request.</li>
                        <li>We verify your identity as described above.</li>
                        <li>We locate the records associated with you and review what can be deleted.</li>
                        <li>We delete or anonymise those records.</li>
                        <li>We confirm to you what was done, and explain anything we had to keep and why.</li>
                    </ol>
                    <p>We aim to deal with requests promptly, and we will tell you if a request will take longer than expected.</p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">What we may need to keep</h2>
                    <p>
                        In some cases we cannot delete everything immediately. We may need to retain limited
                        information where we are legally required to, where it is needed to establish or defend a
                        legal claim, or where it is necessary for the security and integrity of our systems — for
                        example, records relating to abuse or fraud. Where that applies, we will tell you what has
                        been kept and why, and we will delete it once the reason no longer applies.
                    </p>
                    <p>
                        Deleting records on our side does not delete the conversation from Facebook or Instagram. To
                        remove it there, use the controls those platforms provide, or remove our app&rsquo;s access
                        from your account settings on the platform.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold tracking-tight">Related</h2>
                    <p>
                        Our{" "}
                        <a href="/privacy-policy" className="underline underline-offset-4">
                            Privacy Policy
                        </a>{" "}
                        describes what information we receive and why. For any other question about your
                        information, email{" "}
                        <a href={`mailto:${CONTACT}`} className="underline underline-offset-4">
                            {CONTACT}
                        </a>
                        .
                    </p>
                </section>
            </div>
        </>
    );
}
