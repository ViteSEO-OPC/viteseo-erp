"use client";

import { useState } from "react";
import { X, RefreshCw, Copy, CheckCircle2, Loader2, UserCheck } from "lucide-react";
import { notify as toast } from "@/core/lib/notify";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { useCreateUser } from "@/modules/crm/features/settings/hooks/use-user-mutations";
import { useUserLookup } from "@/modules/crm/features/settings/hooks/use-user-lookup";
import { useCopyToClipboard } from "@/modules/crm/hooks/use-copy-to-clipboard";
import { ROLE_LABELS, isRole, type Role } from "@/modules/crm/lib/rbac";
import { FIELD, SELECT } from "@/modules/crm/features/settings/components/form-styles";
import type { CentralUser, CreatedUser } from "@/modules/crm/features/settings/types/user-types";
import { cn } from "@/modules/crm/lib/utils";
import { SelectShell } from "@/modules/crm/components/shared/select-shell/select-shell";

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

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  assignableRoles: Role[];
  defaultRole: Role;
};

/**
 * Create Account — **email-first**.
 *
 * The platform issues ONE account per person across TeamOra (HRIS), Zerem (CRM) and Sprintly
 * (Projects), so most emails typed here already belong to a colleague. The modal therefore asks the
 * API who owns the address before it commits to a shape:
 *
 *  - **Existing centralized user** → a "Grant Zerem Access" confirmation. Name and temporary
 *    password disappear entirely, because granting access adds a CRM role to the account they
 *    already have and must not touch their password, name, or status.
 *  - **New address** → the original create flow, unchanged.
 */
export function CreateAccountModal({ open, onOpenChange, assignableRoles, defaultRole }: Props) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  // The address we've actually asked the API about — set on blur, not on every keystroke.
  const [checkedEmail, setCheckedEmail] = useState("");
  const [role, setRole] = useState<Role>(defaultRole);
  const [password, setPassword] = useState("");
  const [created, setCreated] = useState<CreatedUser | null>(null);
  const createUser = useCreateUser();
  const { copy } = useCopyToClipboard();
  const { existingUser, isChecking } = useUserLookup(checkedEmail);

  const showRoleSelect = assignableRoles.length > 1;
  const bdOnly = assignableRoles.length === 1 && assignableRoles[0] === "crm_bd";
  // Only trust the lookup while the field still holds the address it answered for.
  const granting = existingUser !== null && existingUser.email === email.trim();
  const title = granting ? "Grant Zerem Access" : bdOnly ? "Create BD Account" : "Create Account";

  const alreadyHasRole = granting && existingUser.crmRole === role;

  const handleClose = () => {
    setName("");
    setEmail("");
    setCheckedEmail("");
    setRole(defaultRole);
    setPassword("");
    setCreated(null);
    onOpenChange(false);
  };

  const handleEmailBlur = () => {
    const trimmed = email.trim();
    setCheckedEmail(looksLikeEmail(trimmed) ? trimmed : "");
  };

  const canSubmit =
    email.trim() !== "" &&
    !createUser.isPending &&
    !isChecking &&
    // A grant needs no name; a create still does.
    (granting || name.trim() !== "");

  const handleSubmit = () => {
    // The grant payload carries email + role ONLY. Sending name/status/password alongside an
    // existing address is rejected by the API by design — this mirrors that contract rather than
    // relying on the server to strip them.
    const payload = granting
      ? { email: email.trim(), role }
      : { name: name.trim(), email: email.trim(), role, ...(password ? { password } : {}) };

    createUser.mutate(payload, { onSuccess: (data) => setCreated(data) });
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
    <Dialog open={open} onOpenChange={(val) => { if (!val) handleClose(); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[440px]">
        {created ? (
          created.granted ? (
            <GrantedCard user={created} onClose={handleClose} />
          ) : (
            <CreatedCard user={created} onClose={handleClose} onCopyDetails={handleCopyDetails} />
          )
        ) : (
          <>
            <DialogHeader className="border-b border-border-subtle px-6 py-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <DialogTitle className="text-lg font-bold text-card-foreground">{title}</DialogTitle>
                  <p className="text-sm text-muted-foreground">
                    {granting
                      ? "This person already has a Vite SEO account — we'll add CRM access to it."
                      : "Just a name and working email — we handle the rest."}
                  </p>
                </div>
                <button type="button" onClick={handleClose} aria-label="Close" className={closeButtonClass}>
                  <X className="h-4 w-4" />
                </button>
              </div>
            </DialogHeader>

            <div className="flex flex-col gap-4 px-6 py-5">
              <FormField label="Working Email" required>
                <div className="relative">
                  <input
                    type="email"
                    className={FIELD}
                    placeholder="name@company.com"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    onBlur={handleEmailBlur}
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
                    aria-required="true"
                  />
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
                      {assignableRoles.map((r) => (
                        <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                      ))}
                    </select>
                  </SelectShell>
                </FormField>
              ) : null}

              {/* No password UI on the grant path — the person keeps the credentials they already
                  use for TeamOra/Sprintly, and the API rejects a password sent with an existing
                  address outright. */}
              {granting ? (
                alreadyHasRole ? (
                  <p className="text-xs text-muted-foreground">
                    {existingUser.name} already has {ROLE_LABELS[role]} access — granting again
                    changes nothing.
                  </p>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    Their password, name and status are not changed. Only CRM access is added.
                  </p>
                )
              ) : (
                <>
                  <FormField label="Temporary Password">
                    <div className="flex gap-2">
                      <input
                        type="text"
                        readOnly
                        className={cn(FIELD, "flex-1 font-mono")}
                        placeholder="Click Generate →"
                        value={password}
                      />
                      <Button type="button" variant="outline" size="sm" onClick={() => setPassword(generateTempPassword())}>
                        <RefreshCw className="mr-1.5 h-3.5 w-3.5" /> Generate
                      </Button>
                      <Button type="button" variant="outline" size="sm" onClick={handleCopyPassword} disabled={!password}>
                        <Copy className="mr-1.5 h-3.5 w-3.5" /> Copy
                      </Button>
                    </div>
                  </FormField>
                  <p className="text-xs text-muted-foreground">
                    Generate a secure temporary password to copy and send. Leave it blank and one will be created automatically.
                  </p>
                </>
              )}
            </div>

            <DialogFooter className="mx-0 mb-0 flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
              <Button variant="outline" size="sm" onClick={handleClose} disabled={createUser.isPending}>
                Cancel
              </Button>
              <Button variant="default" size="sm" onClick={handleSubmit} disabled={!canSubmit}>
                {createUser.isPending
                  ? granting ? "Granting…" : "Creating…"
                  : granting ? "Grant Zerem Access" : "Create account"}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
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
      className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3"
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

/** Success card for a newly created account — unchanged, credentials included. */
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
            Share these sign-in details with the BD. They will change the password at first sign-in.
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
