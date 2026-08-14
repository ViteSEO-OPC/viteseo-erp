"use client";

import { useState } from "react";
import { X, RefreshCw, Copy, CheckCircle2 } from "lucide-react";
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
import { useCopyToClipboard } from "@/modules/crm/hooks/use-copy-to-clipboard";
import { ROLE_LABELS, type Role } from "@/modules/crm/lib/rbac";
import { FIELD, SELECT } from "@/modules/crm/features/settings/components/form-styles";
import type { CreatedUser } from "@/modules/crm/features/settings/types/user-types";
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

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  assignableRoles: Role[];
  defaultRole: Role;
};

export function CreateAccountModal({ open, onOpenChange, assignableRoles, defaultRole }: Props) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<Role>(defaultRole);
  const [password, setPassword] = useState("");
  const [created, setCreated] = useState<CreatedUser | null>(null);
  const createUser = useCreateUser();
  const { copy } = useCopyToClipboard();

  const showRoleSelect = assignableRoles.length > 1;
  const bdOnly =
    assignableRoles.length === 1 && assignableRoles[0] === "crm_bd";
  const title = bdOnly ? "Create BD Account" : "Create Account";

  const handleClose = () => {
    setName("");
    setEmail("");
    setRole(defaultRole);
    setPassword("");
    setCreated(null);
    onOpenChange(false);
  };

  const canSubmit = name.trim() !== "" && email.trim() !== "" && !createUser.isPending;

  const handleCreate = () => {
    createUser.mutate(
      { name: name.trim(), email: email.trim(), role, ...(password ? { password } : {}) },
      { onSuccess: (data) => setCreated(data) },
    );
  };

  const handleCopyPassword = async () => {
    if (!password) return;
    if (await copy(password)) toast.success("Password copied");
  };

  const handleCopyDetails = async () => {
    if (!created) return;
    const text = `Name: ${created.user.name}\nEmail: ${created.user.email}\nTemporary password: ${created.temporaryPassword}`;
    if (await copy(text)) toast.success("Details copied");
  };

  return (
    <Dialog open={open} onOpenChange={(val) => { if (!val) handleClose(); }}>
      <DialogContent showCloseButton={false} className="flex w-full flex-col gap-0 p-0 sm:max-w-[440px]">
        {created ? (
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
              <button type="button" onClick={handleClose} aria-label="Close" className={cn(closeButtonClass, "ml-auto")}>
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="divide-y divide-border-subtle rounded-lg border border-border bg-muted/40">
              <DetailRow label="Name" value={created.user.name} />
              <DetailRow label="Email" value={created.user.email} />
              <DetailRow label="Password" value={created.temporaryPassword} mono />
            </div>
            <div className="mt-5 flex items-center justify-end gap-3">
              <Button variant="outline" size="sm" onClick={handleCopyDetails}>
                <Copy className="mr-1.5 h-3.5 w-3.5" /> Copy details
              </Button>
              <Button variant="default" size="sm" onClick={handleClose}>Done</Button>
            </div>
          </div>
        ) : (
          <>
            <DialogHeader className="border-b border-border-subtle px-6 py-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <DialogTitle className="text-lg font-bold text-card-foreground">{title}</DialogTitle>
                  <p className="text-sm text-muted-foreground">
                    Just a name and working email — we handle the rest.
                  </p>
                </div>
                <button type="button" onClick={handleClose} aria-label="Close" className={closeButtonClass}>
                  <X className="h-4 w-4" />
                </button>
              </div>
            </DialogHeader>

            <div className="flex flex-col gap-4 px-6 py-5">
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
              <FormField label="Working Email" required>
                <input
                  type="email"
                  className={FIELD}
                  placeholder="name@company.com"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  aria-required="true"
                />
              </FormField>
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
            </div>

            <DialogFooter className="mx-0 mb-0 flex-row justify-end gap-3 border-t border-border-subtle px-6 py-4">
              <Button variant="outline" size="sm" onClick={handleClose} disabled={createUser.isPending}>
                Cancel
              </Button>
              <Button variant="default" size="sm" onClick={handleCreate} disabled={!canSubmit}>
                {createUser.isPending ? "Creating…" : "Create account"}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
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
