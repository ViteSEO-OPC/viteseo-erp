"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { AlertTriangle, CheckCircle2, Lock, PhoneCall } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Switch } from "@/modules/crm/components/ui/switch";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";
import {
  useCallingIntegration,
  useClearCallingCredentials,
  useConfigureCalling,
  useTestCallingConnection,
  type CallingIntegration,
} from "@/modules/crm/features/settings/hooks/use-calling-integration";
import {
  buildConfigurePayload,
  callingCardMode,
  callingState,
  credentialFormDefaults,
  credentialSchema,
  fieldLabel,
  formFieldErrors,
  missingByOwner,
  testFailureRemedy,
  testOutcome,
  testStatusLabel,
  voiceWebhookUrl,
  type CredentialFormValues,
} from "@/modules/crm/features/settings/lib/calling-settings";

const cardClass = "border border-border bg-card p-6 shadow-card";

/**
 * Settings → Integrations → Calling.
 *
 * ── Two kinds of organization ──
 * CENTRALLY MANAGED — ViteSEO's own organization, and every organization in a deployment that runs one
 * centralized account. Its calling runs on ViteSEO's Twilio account, configured on the server. The card
 * is a read-only STATUS: managed by ViteSEO, whether browser calling is on, and that manual calling
 * carries on when it is not. No form, no switch, no test and no secret — the server refuses every write
 * for such an organization anyway.
 *
 * CLIENT — an organization that brings its OWN Twilio account. Its admin enters the credentials here.
 * Without its own account it has manual calling; it is never carried on ViteSEO's account.
 *
 * Every decision below — which fields exist, what a save sends, which state the card claims — lives in
 * `lib/calling-settings`, where `npm run calling:gates` pins it.
 *
 * ── The distinction this card exists to make legible ──
 * CONFIGURED IS NOT RUNNING. Browser calling needs three things at once — the switch on, every field
 * filled, and Twilio's own confirmation that the credentials work — so an admin can have saved a
 * complete configuration and still, correctly, not have the feature:
 *
 *   Not configured → Saved → Verified by Twilio → Browser calling on
 *
 * The header chip is computed from what the SERVER says is in force, never from the form's contents,
 * so a half-filled or unverified configuration can never read as a working one.
 *
 * ── It never holds a secret ──
 * The API sends `hasAuthToken` / `hasApiSecret` and the last four characters, and nothing else. Both
 * secret inputs are therefore write-only: they start empty, show a masked placeholder when something
 * is stored, and are never populated from a response. Leaving one blank on save keeps what is stored.
 *
 * ── The callback address is not the organization's ──
 * Where Twilio calls back is the deployment's public origin. It is shown — read-only — as the Voice URL
 * the organization's TwiML App must use, and there is no input for it.
 *
 * ── Manual calling is not affected by anything here ──
 * Turning this off, misconfiguring it, or never touching it leaves the CRM's free calling workflow
 * exactly as it is. That is stated on the card, because an admin looking at an integration screen
 * should not have to wonder whether they are about to break calling for the whole team.
 */
export function TwilioCallingCard() {
  const { integration, isLoading, isError, isForbidden, retry } = useCallingIntegration();

  return (
    <Card className={cardClass}>
      <div className="flex flex-col gap-5">
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted">
              <PhoneCall className="size-4 text-teal-700 dark:text-teal-400" aria-hidden />
            </span>
            <div>
              <h3 className="text-base font-semibold text-foreground">Calling</h3>
              <p className="mt-0.5 text-sm text-muted-foreground">
                {integration?.centrallyManaged
                  ? "Browser calling through ViteSEO's centrally managed Twilio account."
                  : "Optional. Connect your organization's own Twilio account to let BDs call leads from the browser."}
              </p>
            </div>
          </div>
          {integration ? <StatusChip integration={integration} /> : null}
        </header>

        {isLoading ? (
          <div className="flex flex-col gap-3" aria-busy>
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        ) : isForbidden ? (
          <p className="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
            Only an Admin can change the calling configuration.
          </p>
        ) : isError || !integration ? (
          <ErrorState
            message="Could not load the calling configuration."
            onRetry={() => void retry()}
          />
        ) : callingCardMode(integration) === "central" ? (
          <CentralStatus integration={integration} />
        ) : (
          <Body integration={integration} />
        )}
      </div>
    </Card>
  );
}

/** What is actually in force, in one chip. Computed from the server's answer, never from the form. */
function StatusChip({ integration }: { integration: CallingIntegration }) {
  if (integration.browserCalling) {
    return (
      <Badge className="gap-1 bg-teal-600 text-white dark:bg-teal-700">
        <CheckCircle2 className="size-3" aria-hidden />
        Browser calling on
      </Badge>
    );
  }

  // Centrally managed, the org's own stored row is not what runs, so it cannot make this amber.
  if (!integration.centrallyManaged && (integration.settings.configured || integration.enabled)) {
    return (
      <Badge
        variant="outline"
        className="gap-1 border-amber-300 bg-amber-100 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
      >
        <AlertTriangle className="size-3" aria-hidden />
        Not running
      </Badge>
    );
  }

  return (
    <Badge variant="outline" className="text-muted-foreground">
      Manual calling
    </Badge>
  );
}

function Body({ integration }: { integration: CallingIntegration }) {
  const configure = useConfigureCalling();
  const clear = useClearCallingCredentials();
  const test = useTestCallingConnection();
  const [confirmRemove, setConfirmRemove] = useState(false);

  const busy = configure.isPending || clear.isPending || test.isPending;
  const { settings } = integration;

  return (
    <>
      {/* The reassurance an admin actually needs on this screen, stated before any input. */}
      <p className="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        Calling works without this. With no Twilio account your team keeps dialing from their own
        phones and logging the outcome in the CRM — connecting your own account only adds the option
        to call from the browser, with the talk time measured by the carrier.
      </p>

      <StateExplanation integration={integration} />

      <SetupChecklist />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-foreground">Enable browser calling</p>
          <p className="text-xs text-muted-foreground">
            Saving credentials does not switch this on — enabling is a separate step.
          </p>
        </div>
        <Switch
          checked={settings.enabled}
          disabled={busy}
          aria-label="Enable browser calling"
          onCheckedChange={(checked) => configure.mutate({ enabled: checked })}
        />
      </div>

      <CredentialsForm integration={integration} busy={busy} />

      {/* --- Connection status --- */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted/40 px-3 py-2.5 dark:bg-muted/60">
        <div className="flex flex-col gap-0.5">
          <span className="flex items-center gap-2 text-sm text-foreground">
            <TestStatusChip integration={integration} />
          </span>
          {settings.lastTestedAt ? (
            <span className="text-xs text-muted-foreground">
              Last checked {new Date(settings.lastTestedAt).toLocaleString()}
            </span>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            size="sm"
            // Promoted to the primary action when a verified test is the ONLY thing standing between
            // this workspace and working browser calling — which, after any credential edit, it is.
            variant={integration.complete && !integration.verified ? "default" : "outline"}
            // Disabled while incomplete: the server would answer `not_configured` without calling
            // out, so offering the button would invite an action that cannot succeed.
            disabled={busy || !integration.complete}
            onClick={() => test.mutate()}
          >
            {test.isPending ? "Testing…" : "Test connection"}
          </Button>
          {settings.hasAuthToken || settings.hasApiSecret ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={() => setConfirmRemove(true)}
            >
              Remove credentials
            </Button>
          ) : null}
        </div>
      </div>

      <p className="text-xs text-muted-foreground">
        A connection test only reads your Twilio account to check the credentials and that your TwiML
        App points at this server — it never places a call and never rings a number. Browser calling
        stays off until a test succeeds, and any change to a credential means testing again.
      </p>

      {/*
        The one thing a green "Connected" does NOT establish, said plainly rather than left to be
        discovered on a real call. Twilio accepts an outbound caller ID both from numbers the account
        owns and from separately verified caller IDs, so checking only the owned-numbers list would
        reject perfectly valid setups — the check is deliberately not made, which makes stating its
        absence part of the feature rather than an admission.
      */}
      <p className="text-xs text-muted-foreground">
        It does not check the Caller ID or whether your account may call a given destination. A passing
        test means the credentials work, not that this number is cleared to place calls — Twilio must
        own it or you must have verified it, and international destinations may need to be enabled on
        your account.
      </p>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={setConfirmRemove}
        title="Remove the Twilio credentials?"
        description="Browser calling stops until a new Auth Token and API Key Secret are saved. Your team keeps calling from their own phones and logging outcomes in the CRM, exactly as they do now."
        confirmLabel="Remove credentials"
        destructive
        loading={clear.isPending}
        onConfirm={() => clear.mutate(undefined, { onSuccess: () => setConfirmRemove(false) })}
      />
    </>
  );
}

/**
 * A centrally managed organization's card: ViteSEO's account, as a status. No form, no switch, no test
 * and no secret — Twilio is configured by ViteSEO on the server, and nothing here can change it.
 */
function CentralStatus({ integration }: { integration: CallingIntegration }) {
  return (
    <>
      <div className="flex items-center gap-2">
        <Badge variant="secondary" className="gap-1">
          <Lock className="size-3" aria-hidden />
          Managed by ViteSEO
        </Badge>
      </div>

      {integration.browserCalling ? (
        <div
          role="status"
          className="flex items-start gap-2 rounded-lg border border-teal-600/40 bg-teal-50/50 px-3 py-2.5 text-sm text-teal-900 dark:border-teal-700/60 dark:bg-muted/40 dark:text-teal-200"
        >
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
          <p>
            Browser calling is on. Calls go through ViteSEO&apos;s centrally configured Twilio account,
            so BDs see “Call from Browser” on any lead with a usable phone number.
          </p>
        </div>
      ) : (
        <div
          role="status"
          className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-sm text-muted-foreground"
        >
          <PhoneCall className="mt-0.5 size-4 shrink-0" aria-hidden />
          <p>
            Browser calling is not available right now, so your team calls from their own phones and
            logs the outcome in the CRM — nothing here needs to change. It becomes available once
            ViteSEO&apos;s central Twilio account is configured on the server.
          </p>
        </div>
      )}

      <p className="text-xs text-muted-foreground">
        This organization&apos;s calling is managed centrally by ViteSEO on the server. Its Twilio
        credentials cannot be viewed or changed here, and no Twilio secret is ever shown on this page.
      </p>
    </>
  );
}

/**
 * Why browser calling is or is not running, in one sentence.
 *
 * Each case names what to DO, because "not running" without a reason is the state an admin cannot
 * get out of. The missing-field list comes from the server, so it can never disagree with what the
 * server would accept — and a field only ViteSEO can supply is named as ViteSEO's, not the admin's.
 */
function StateExplanation({ integration }: { integration: CallingIntegration }) {
  const state = callingState(integration);

  if (state === "connected") {
    return (
      <div
        role="status"
        className="flex items-start gap-2 rounded-lg border border-teal-600/40 bg-teal-50/50 px-3 py-2.5 text-sm text-teal-900 dark:border-teal-700/60 dark:bg-muted/40 dark:text-teal-200"
      >
        <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
        <p>
          Browser calling is running on your organization&apos;s Twilio account. BDs see “Call from
          Browser” on any lead with a usable phone number.
        </p>
      </div>
    );
  }

  if (state === "not-configured" || state === "centrally-managed") {
    return null; // Nothing configured and nothing claimed — the form below is the whole message.
  }

  const { client, server } = missingByOwner(integration.missing);

  const reason =
    state === "incomplete"
      ? [
          client.length > 0 ? `Still needed: ${client.map(fieldLabel).join(", ")}.` : null,
          server.length > 0
            ? "ViteSEO still needs to set this server's public callback address — contact ViteSEO."
            : null,
        ]
          .filter(Boolean)
          .join(" ")
      : state === "switched-off"
        ? "The switch above is off."
        : state === "test-failed"
          ? `${testStatusLabel(integration.settings.lastTestStatus)}. ${testFailureRemedy(integration.settings.lastTestStatus)}`
          : "Run Test connection to confirm the credentials — browser calling starts as soon as Twilio accepts them.";

  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/20 dark:text-amber-200"
    >
      <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
      <p>
        Browser calling is not running, so your team is calling from their own phones. {reason}
      </p>
    </div>
  );
}

/**
 * The form: exactly the credential fields the API accepts (see `CLIENT_CREDENTIAL_FIELDS`). Identifiers
 * and secrets are submitted together, but only the secrets are write-only — an empty secret field
 * means "keep what is stored", which is why neither is ever pre-filled and both show a masked hint.
 *
 * The shapes are validated here exactly as the server validates them, so a paste error is caught
 * before a round trip; anything the server still rejects is shown on the field it names.
 */
function CredentialsForm({
  integration,
  busy,
}: {
  integration: CallingIntegration;
  busy: boolean;
}) {
  const configure = useConfigureCalling();
  const { settings } = integration;
  const voiceUrl = voiceWebhookUrl(settings);

  const form = useForm<CredentialFormValues>({
    resolver: zodResolver(credentialSchema),
    defaultValues: credentialFormDefaults(settings),
  });

  const submit = form.handleSubmit((values) => {
    configure.mutate(buildConfigurePayload(values), {
      onSuccess: () => {
        // Clear the two write-only inputs so a saved secret is never left sitting in the DOM, and so
        // the placeholder goes back to being the only indication of what is stored.
        form.resetField("auth_token");
        form.resetField("api_secret");
      },
      onError: (err) => {
        // The server's own sentence for each rejected field, on that field. The summary toast is
        // raised by the mutation itself.
        for (const [field, message] of Object.entries(formFieldErrors(err))) {
          form.setError(field as keyof CredentialFormValues, { type: "server", message });
        }
      },
    });
  });

  return (
    <form className="flex flex-col gap-4 border-t border-border-subtle pt-4" onSubmit={submit}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="twilio-account-sid"
          label="Account SID"
          hint="Twilio Console → Account Info"
          placeholder="AC…"
          error={form.formState.errors.account_sid?.message}
          {...form.register("account_sid")}
        />
        <SecretField
          id="twilio-auth-token"
          label="Auth Token"
          hint="Validates Twilio's webhooks. Never leaves the server."
          stored={settings.hasAuthToken}
          last4={settings.authTokenLast4}
          error={form.formState.errors.auth_token?.message}
          {...form.register("auth_token")}
        />
        <Field
          id="twilio-api-key"
          label="API Key SID"
          hint="Twilio Console → Account → API keys & tokens"
          placeholder="SK…"
          error={form.formState.errors.api_key?.message}
          {...form.register("api_key")}
        />
        <SecretField
          id="twilio-api-secret"
          label="API Key Secret"
          hint="Shown by Twilio only once, when the key is created."
          stored={settings.hasApiSecret}
          last4={settings.apiSecretLast4}
          error={form.formState.errors.api_secret?.message}
          {...form.register("api_secret")}
        />
        <Field
          id="twilio-twiml-app"
          label="TwiML App SID"
          hint="The Voice app in your Twilio account whose request URL is shown below."
          placeholder="AP…"
          error={form.formState.errors.twiml_app_sid?.message}
          {...form.register("twiml_app_sid")}
        />
        <Field
          id="twilio-caller-id"
          label="Caller ID"
          hint="The number the person you call will see. It must be a number this Twilio account owns, or a caller ID you have verified with Twilio — the connection test cannot check this."
          placeholder="+639171234567"
          error={form.formState.errors.caller_id?.message}
          {...form.register("caller_id")}
        />
      </div>

      <VoiceUrlNote url={voiceUrl} />

      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" size="sm" disabled={busy}>
          {configure.isPending ? "Saving…" : "Save"}
        </Button>
        <p className="text-xs text-muted-foreground">
          Secrets are encrypted before they are stored and are never sent back to this page. Leave one
          blank to keep the value already saved.
        </p>
      </div>
    </form>
  );
}

/**
 * What an admin has to have done in Twilio BEFORE this form can be filled in, and in what order.
 *
 * Collapsed by default: an admin returning to correct a caller ID should not have to scroll past a
 * tutorial, and an admin arriving with nothing should not have to guess that four of the six values
 * do not exist yet. The ordering is the part that is not obvious — the TwiML App has to exist before
 * its SID can be pasted, and it needs the Voice URL shown below it, which is why that note sits
 * directly under the form rather than here.
 *
 * Deliberately not a link to external documentation: Twilio's console changes, and a dead link on a
 * setup screen is worse than no link. The full walkthrough lives in ViteSEO's client setup guide.
 */
function SetupChecklist() {
  return (
    <details className="rounded-lg border border-border-subtle bg-muted/30 px-3 py-2.5">
      <summary className="cursor-pointer text-sm font-medium text-foreground">
        What you&apos;ll need from Twilio
      </summary>
      <ol className="mt-2.5 flex list-decimal flex-col gap-1.5 pl-5 text-xs text-muted-foreground">
        <li>
          A Twilio account (or a subaccount for this organization) — its{" "}
          <span className="font-medium text-foreground">Account SID</span> and{" "}
          <span className="font-medium text-foreground">Auth Token</span> are on the console dashboard.
        </li>
        <li>
          An <span className="font-medium text-foreground">API Key</span> created under that account —
          this gives you the API Key SID and its Secret. Copy the Secret immediately: Twilio shows it
          once and never again.
        </li>
        <li>
          A phone number the account owns, or a caller ID you have verified with Twilio. That number is
          the <span className="font-medium text-foreground">Caller ID</span> people see when your team
          calls.
        </li>
        <li>
          A <span className="font-medium text-foreground">TwiML App</span> (Voice), whose Voice Request
          URL is the address shown below this form, with the method set to HTTP POST. Create it before
          you fill the form in — its SID is one of the six values.
        </li>
        <li>Paste all six values here, Save, then run Test connection. Browser calling starts when Twilio accepts them.</li>
      </ol>
      <p className="mt-2.5 text-xs text-muted-foreground">
        Twilio bills this account directly. A separate Twilio account or subaccount per organization is
        recommended, so billing, limits and key rotation stay independent.
      </p>
    </details>
  );
}

/**
 * Where the organization's TwiML App must send Twilio's Voice requests. Read-only: it is this
 * deployment's public address, fixed on the server, and deliberately not a field anyone here can edit.
 */
function VoiceUrlNote({ url }: { url: string | null }) {
  return (
    <div className="flex flex-col gap-1.5 rounded-lg bg-muted/40 px-3 py-2.5">
      <p className="text-sm font-medium text-foreground">Voice request URL for your TwiML App</p>
      {url ? (
        <>
          <p className="break-all rounded bg-card px-2 py-1 font-mono text-xs text-foreground">{url}</p>
          <p className="text-xs text-muted-foreground">
            Set this as your TwiML App&apos;s Voice Request URL (HTTP POST). It is fixed by this
            deployment and cannot be changed here.
          </p>
        </>
      ) : (
        <p className="text-xs text-muted-foreground">
          This server&apos;s public callback address has not been set up yet. ViteSEO sets it — it
          cannot be changed here.
        </p>
      )}
    </div>
  );
}

type FieldProps = {
  id: string;
  label: string;
  hint: string;
  placeholder?: string;
  error?: string;
} & React.ComponentPropsWithoutRef<"input">;

function Field({ id, label, hint, error, className, ...input }: FieldProps) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      <input id={id} autoComplete="off" className={cn(FIELD, className)} {...input} />
      <p className="text-xs text-muted-foreground">{hint}</p>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  );
}

/** A write-only field: never pre-filled, and the placeholder is the only hint at what is stored. */
function SecretField({
  id,
  label,
  hint,
  stored,
  last4,
  error,
  ...input
}: FieldProps & { stored: boolean; last4: string | null }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="flex items-center gap-1.5 text-sm font-medium text-foreground">
        <Lock className="size-3.5 text-muted-foreground" aria-hidden />
        {label}
      </label>
      <input
        id={id}
        type="password"
        autoComplete="off"
        className={FIELD}
        placeholder={stored ? `••••••••${last4 ?? ""}` : "Paste the value"}
        {...input}
      />
      <p className="text-xs text-muted-foreground">
        {stored ? "Saved. Leave blank to keep it. " : ""}
        {hint}
      </p>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  );
}

function TestStatusChip({ integration }: { integration: CallingIntegration }) {
  const outcome = testOutcome(integration.settings.lastTestStatus);

  if (outcome.kind === "success") {
    return (
      <Badge variant="secondary" className="gap-1">
        <CheckCircle2 className="size-3 text-teal-700 dark:text-teal-400" aria-hidden />
        {outcome.label}
      </Badge>
    );
  }

  if (outcome.kind === "failure") {
    return (
      <Badge
        variant="outline"
        className="gap-1 border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-300"
      >
        <AlertTriangle className="size-3" aria-hidden />
        {outcome.label}
      </Badge>
    );
  }

  // Never tested, or tested with nothing to test — stated plainly rather than dressed as a status.
  return <Badge variant="outline">{outcome.label}</Badge>;
}
