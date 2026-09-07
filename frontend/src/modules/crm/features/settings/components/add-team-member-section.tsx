"use client";

import { useState } from "react";
import { CheckCircle2, Copy, Loader2, RefreshCw, UserCheck, UserPlus, X } from "lucide-react";

import { notify as toast } from "@/core/lib/notify";
import { useCopyToClipboard } from "@/core/use-copy-to-clipboard";
import { useTeamAccounts } from "@/core/team/use-team-accounts";
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import {
  Progress,
  ProgressIndicator,
  ProgressTrack,
} from "@/modules/crm/components/ui/progress";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { SelectShell } from "@/modules/crm/components/shared/select-shell/select-shell";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { assignableRoles as computeAssignable, ROLE_LABELS, isRole, type Role } from "@/modules/crm/lib/rbac";
import { useCreateUser } from "@/modules/crm/features/settings/hooks/use-user-mutations";
import { useUserLookup } from "@/modules/crm/features/settings/hooks/use-user-lookup";
import { FIELD, SELECT } from "@/modules/crm/features/settings/components/form-styles";
import type { CentralUser, CreatedUser } from "@/modules/crm/features/settings/types/user-types";
import { cn } from "@/modules/crm/lib/utils";

const closeButtonClass =
  "flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

/** Client-side strong temp password (server hashes it; or generates its own if left blank). */
function generateTempPassword(length = 16): string {
  const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%";
  const arr = new Uint32Array(length);
  crypto.getRandomValues(arr);
  return Array.from(arr, (n) => chars[n % chars.length]).join("");
}

/** Cheap client-side gate so we only ask the API about something shaped like an address. */
function looksLikeEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

/**
 * Add a member — an inline panel, not a dialog, matching TeamOra and Sprintly.
 *
 * A dialog put the one thing an empty team page exists for behind a button, and
 * hid the seat count with it. The one thing that still opens over the page is the
 * hand-over card, because the operator must finish that before doing anything else.
 *
 * EMAIL-FIRST, and that is what keeps this on /users rather than on the /crm/team
 * POST the other two portals use. The platform issues ONE account per person across
 * TeamOra, Zerem and Sprintly, so most addresses typed here already belong to a
 * colleague. The API is asked who owns the address before the form commits to a shape:
 *
 *  - **Existing account** → a "Grant Zerem Access" confirmation. Name and temporary
 *    password disappear entirely, because granting adds a CRM role to the account
 *    they already have and must not touch their password, name or status.
 *  - **New address** → a create, with the credentials handed back once.
 *
 * /crm/team's POST can express neither branch: it always creates, and its
 * `unique:users,email` refuses the colleague this flow exists to reach.
 */
export function AddTeamMemberSection() {
  const { user } = useAuth();
  const assignable = computeAssignable(user?.roles);

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  // The address we've actually asked the API about — set on blur, not on every keystroke.
  const [checkedEmail, setCheckedEmail] = useState("");
  const [role, setRole] = useState<Role>("crm_bd");
  const [password, setPassword] = useState("");
  const [created, setCreated] = useState<CreatedUser | null>(null);

  const createUser = useCreateUser();
  const { copy } = useCopyToClipboard();
  const { existingUser, isChecking } = useUserLookup(checkedEmail);
  // Seats are the ORG's, counted across every system it uses, so this is the same
  // number TeamOra, Sprintly and billing report.
  const seats = useTeamAccounts("crm").data?.seats;

  const showRoleSelect = assignable.length > 1;
  const bdOnly = assignable.length === 1 && assignable[0] === "crm_bd";
  // Only trust the lookup while the field still holds the address it answered for.
  const granting = existingUser !== null && existingUser.email === email.trim();
  const alreadyHasRole = granting && existingUser.crmRole === role;
  const atLimit = seats ? seats.used >= seats.limit : false;

  const submitLabel = granting
    ? "Grant Zerem access"
    : bdOnly
      ? "Create BD account"
      : "Create account";

  const handleEmailBlur = () => {
    const trimmed = email.trim();
    setCheckedEmail(looksLikeEmail(trimmed) ? trimmed : "");
  };

  const canSubmit =
    email.trim() !== "" &&
    assignable.length > 0 &&
    !createUser.isPending &&
    !isChecking &&
    // A grant needs no name; a create still does.
    (granting || name.trim() !== "");

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    if (!canSubmit) return;

    // The grant payload carries email + role ONLY. Sending name/status/password alongside an
    // existing address is rejected by the API by design — this mirrors that contract rather than
    // relying on the server to strip them.
    const payload = granting
      ? { email: email.trim(), role }
      : { name: name.trim(), email: email.trim(), role, ...(password ? { password } : {}) };

    createUser.mutate(payload, {
      onSuccess: (data) => {
        setCreated(data);
        // Only the identity fields are cleared: an admin adding three people in a
        // row is usually putting them all on the same role.
        setName("");
        setEmail("");
        setCheckedEmail("");
        setPassword("");
      },
    });
  };

  const handleCopyPassword = async () => {
    if (!password) return;
    if (await copy(password)) toast.success("Password copied");
  };

  const handleCopyDetails = async () => {
    if (!created?.temporaryPassword) return;
    const text = `Name: ${created.user.name}\nEmail: ${created.user.email}\nTemporary password: ${created.temporaryPassword}`;
    if (await copy(text)) toast.success("Details copied");
  };

  return (
    <section>
      {/* Full width, like the Members and role sections below it. Three stacked
          sections at two different widths look like a mistake; the operator sees
          the page, not the form. */}
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-bold text-foreground">Add a member</h2>
        {seats ? (
          <p className="text-sm font-semibold text-muted-foreground">
            {seats.used} of {seats.limit} seats used
          </p>
        ) : null}
      </div>
      {seats ? (
        <Progress
          value={seats.used}
          max={seats.limit}
          className="mt-2"
          aria-label="Seats used"
        >
          <ProgressTrack className="h-2 rounded-full bg-secondary">
            <ProgressIndicator
              className="rounded-full bg-primary transition-all"
              style={{
                width: `${Math.min(100, seats.limit > 0 ? (seats.used / seats.limit) * 100 : 100)}%`,
              }}
            />
          </ProgressTrack>
        </Progress>
      ) : null}
      <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
        Start with the working email. If it already belongs to a Vite SEO Systems
        account, this grants that person Zerem access instead of creating a second
        one. A seat is one account in this organisation, counted across every
        system it uses — not per module.
      </p>

      <div className="mt-4 rounded-xl border border-border bg-card p-4 shadow-card sm:p-6">
        {/* Stated, not enforced. The create path here is /users, which the platform
            does not seat-gate the way /crm/team's POST does, so disabling the form
            would refuse a write the server would honour. */}
        {atLimit ? (
          <div className="mb-4 rounded-lg border border-yellow-800/20 bg-[var(--color-warning-bg)] p-3">
            <p className="text-sm font-semibold text-yellow-800 dark:text-chart-3">
              Every seat is in use
            </p>
            <p className="mt-1 text-sm text-yellow-800/90 dark:text-chart-3">
              All {seats?.limit} seats are taken. Remove someone who has left, or ask
              the platform admin to add a block of seats to the subscription.
            </p>
          </div>
        ) : null}

        {assignable.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Your role cannot create Zerem accounts.
          </p>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Working email" required>
                <div className="relative">
                  <input
                    type="email"
                    className={FIELD}
                    placeholder="name@company.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    onBlur={handleEmailBlur}
                    autoComplete="off"
                    aria-required="true"
                    aria-describedby={granting ? "existing-account-summary" : undefined}
                  />
                  {isChecking ? (
                    <Loader2
                      className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground"
                      aria-hidden
                    />
                  ) : null}
                </div>
              </FormField>

              {granting ? (
                <ExistingAccountSummary user={existingUser} />
              ) : (
                <FormField label="Name" required>
                  <input
                    type="text"
                    className={FIELD}
                    placeholder="e.g. Andrea Lim"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    autoComplete="off"
                    aria-required="true"
                  />
                </FormField>
              )}

              {/* No password UI on the grant path — the person keeps the credentials they
                  already use for TeamOra/Sprintly, and the API rejects a password sent with
                  an existing address outright. */}
              {granting ? null : (
                <FormField label="Temporary password">
                  <div className="flex gap-2">
                    <input
                      type="text"
                      readOnly
                      className={cn(FIELD, "flex-1 font-mono")}
                      placeholder="Blank generates one"
                      value={password}
                    />
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => setPassword(generateTempPassword())}
                    >
                      <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Generate
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={handleCopyPassword}
                      disabled={!password}
                    >
                      <Copy className="mr-1.5 h-3.5 w-3.5" /> Copy
                    </Button>
                  </div>
                </FormField>
              )}

              {showRoleSelect ? (
                <FormField label="Role" required>
                  <SelectShell>
                    <select
                      className={SELECT}
                      value={role}
                      onChange={(e) => setRole(e.target.value as Role)}
                    >
                      {assignable.map((r) => (
                        <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                      ))}
                    </select>
                  </SelectShell>
                </FormField>
              ) : null}
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
              <Button type="submit" variant="default" size="sm" disabled={!canSubmit}>
                <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                {createUser.isPending
                  ? granting ? "Granting…" : "Creating…"
                  : submitLabel}
              </Button>
              <p className="text-xs text-muted-foreground">
                {granting
                  ? alreadyHasRole
                    ? `${existingUser.name} already has ${ROLE_LABELS[role]} access — granting again changes nothing.`
                    : "Their password, name and status are not changed. Only CRM access is added."
                  : "Whatever password is used is shown once, right after this, for you to hand over. Ask them to change it at first sign-in."}
              </p>
            </div>

            {/* Only the built-in roles are offered here: POST /users validates against
                the caller's assignable ROLES and has no notion of one of the org's own.
                Put them on a custom role from their row once they exist. */}
            <p className="text-xs text-muted-foreground">
              To put someone on one of your organisation&rsquo;s own roles, create
              them here and then pick that role in their row below.
            </p>
          </form>
        )}
      </div>

      {/* Outside the measure: a dialog is portalled and centres on the viewport. */}
      {created ? (
        <Dialog open onOpenChange={(open) => { if (!open) setCreated(null); }}>
          <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[440px]">
            {created.granted ? (
              <GrantedCard user={created} onClose={() => setCreated(null)} />
            ) : (
              <CreatedCard
                user={created}
                onClose={() => setCreated(null)}
                onCopyDetails={handleCopyDetails}
              />
            )}
          </DialogContent>
        </Dialog>
      ) : null}
    </section>
  );
}

/**
 * Who the address belongs to and what they can already reach — the confirmation that the admin is
 * adding to the right existing account rather than minting a parallel one.
 */
function ExistingAccountSummary({ user }: { user: CentralUser }) {
  return (
    <div
      id="existing-account-summary"
      className="flex items-start gap-3 self-end rounded-lg border border-border bg-muted/40 px-4 py-3"
    >
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-500/10 text-teal-600">
        <UserCheck className="h-4.5 w-4.5" aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-card-foreground">{user.name}</p>
        <p className="truncate text-xs text-muted-foreground">{user.email}</p>
        <div className="mt-1.5 flex flex-wrap gap-1.5">
          {user.systems.length > 0 ? (
            user.systems.map((system) => (
              <span
                key={system}
                className="inline-flex items-center rounded-full bg-card px-2 py-0.5 text-[11px] font-medium text-secondary-foreground"
              >
                {system}
              </span>
            ))
          ) : (
            <span className="text-[11px] text-muted-foreground">No system access yet</span>
          )}
        </div>
      </div>
    </div>
  );
}

/** Success card for a grant — deliberately shows no credentials. */
function GrantedCard({ user, onClose }: { user: CreatedUser; onClose: () => void }) {
  const roleLabel = user.user.role && isRole(user.user.role) ? ROLE_LABELS[user.user.role] : "CRM";

  return (
    <div className="flex flex-col p-6">
      <div className="mb-4 flex items-start gap-3">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-teal-500/10 text-teal-600">
          <CheckCircle2 className="h-6 w-6" aria-hidden />
        </span>
        <div className="min-w-0">
          <DialogTitle className="text-lg font-bold text-card-foreground">Zerem access granted</DialogTitle>
          <p className="text-sm text-muted-foreground">
            {user.user.name} can now sign in to Zerem as {roleLabel} with their existing Vite SEO
            password — nothing to hand over.
          </p>
        </div>
        <button type="button" onClick={onClose} aria-label="Close" className={cn(closeButtonClass, "ml-auto")}>
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className="divide-y divide-border-subtle rounded-lg border border-border bg-muted/40">
        <DetailRow label="Name" value={user.user.name} />
        <DetailRow label="Email" value={user.user.email} />
        <DetailRow label="Role" value={roleLabel} />
      </div>
      <div className="mt-5 flex items-center justify-end">
        <Button variant="default" size="sm" onClick={onClose}>Done</Button>
      </div>
    </div>
  );
}

/**
 * Success card for a newly created account.
 *
 * Shown ONCE, from the value the server just returned. Nothing here is cached or
 * re-fetchable: closing this card is the last time anyone sees the password.
 */
function CreatedCard({
  user,
  onClose,
  onCopyDetails,
}: {
  user: CreatedUser;
  onClose: () => void;
  onCopyDetails: () => void;
}) {
  return (
    <div className="flex flex-col p-6">
      <div className="mb-4 flex items-start gap-3">
        <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-teal-500/10 text-teal-600">
          <CheckCircle2 className="h-6 w-6" aria-hidden />
        </span>
        <div className="min-w-0">
          <DialogTitle className="text-lg font-bold text-card-foreground">Account created</DialogTitle>
          <p className="text-sm text-muted-foreground">
            Share these sign-in details with them. They will change the password at first sign-in.
          </p>
        </div>
        <button type="button" onClick={onClose} aria-label="Close" className={cn(closeButtonClass, "ml-auto")}>
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className="divide-y divide-border-subtle rounded-lg border border-border bg-muted/40">
        <DetailRow label="Name" value={user.user.name} />
        <DetailRow label="Email" value={user.user.email} />
        <DetailRow label="Password" value={user.temporaryPassword ?? "—"} mono />
      </div>
      <div className="mt-5 flex items-center justify-end gap-3">
        <Button variant="outline" size="sm" onClick={onCopyDetails}>
          <Copy className="mr-1.5 h-3.5 w-3.5" /> Copy details
        </Button>
        <Button variant="default" size="sm" onClick={onClose}>Done</Button>
      </div>
    </div>
  );
}

function DetailRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex items-center justify-between gap-4 px-4 py-3">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span
        className={cn(
          "text-sm font-semibold text-card-foreground",
          mono && "rounded bg-card px-2 py-0.5 font-mono",
        )}
      >
        {value}
      </span>
    </div>
  );
}
