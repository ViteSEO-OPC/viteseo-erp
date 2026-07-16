"use client";

import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Switch } from "@/modules/crm/components/ui/switch";
import { FormField, FormFieldGrid } from "@/modules/crm/components/forms/form-field/form-field";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { highestRole, ROLE_LABELS } from "@/modules/crm/lib/rbac";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";

function initialsOf(name: string | undefined): string {
  if (!name) return "U";
  return name
    .split(" ")
    .map((w) => w[0])
    .filter(Boolean)
    .join("")
    .slice(0, 2)
    .toUpperCase();
}

const cardClass = "border border-border bg-card p-6 shadow-card";

/**
 * General settings — the signed-in user's own profile / security / preferences.
 * UI-first for this milestone: fields are prefilled from the current session but not
 * yet persisted (profile save, password change, 2FA, and preferences are deferred).
 */
export function GeneralPage() {
  const { user } = useAuth();
  const role = highestRole(user?.roles);
  const roleLabel = role ? ROLE_LABELS[role] : "—";

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Account Settings</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          You are signed in as {user?.name ?? "your account"}. Manage your profile, security, and preferences.
        </p>
      </header>

      {/* Profile information */}
      <Card className={cardClass}>
        <h3 className="text-base font-bold text-card-foreground">Profile information</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Your details as they appear across the workspace.
        </p>

        <div className="mb-6 flex items-center gap-4">
          <AvatarInitials initials={initialsOf(user?.name)} size="md" />
          <div>
            <p className="text-sm font-semibold text-card-foreground">Profile photo</p>
            <p className="mb-2 text-xs text-muted-foreground">PNG or JPG, up to 4 MB.</p>
            <div className="flex gap-2">
              <Button variant="default" size="sm">Change photo</Button>
              <Button variant="outline" size="sm">Remove</Button>
            </div>
          </div>
        </div>

        <div className="flex flex-col gap-5">
          <FormFieldGrid>
            <FormField label="Full Name">
              <input type="text" className={FIELD} defaultValue={user?.name ?? ""} />
            </FormField>
            <FormField label="Role">
              <input type="text" className={FIELD} defaultValue={roleLabel} readOnly />
            </FormField>
          </FormFieldGrid>
          <FormFieldGrid>
            <FormField label="Email Address">
              <input type="email" className={FIELD} defaultValue={user?.email ?? ""} />
            </FormField>
            <FormField label="Phone">
              <input type="tel" className={FIELD} placeholder="+63 917 000 0000" />
            </FormField>
          </FormFieldGrid>
        </div>

        <div className="mt-6 flex items-center justify-end gap-3">
          <Button variant="outline" size="sm">Cancel</Button>
          <Button variant="default" size="sm">Save changes</Button>
        </div>
      </Card>

      {/* Login & security */}
      <Card className={cardClass}>
        <h3 className="text-base font-bold text-card-foreground">Login &amp; security</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Manage your password and sign-in protection.
        </p>

        <div className="flex flex-col gap-5">
          <FormField label="Current Password">
            <input type="password" className={FIELD} placeholder="••••••••••" />
          </FormField>
          <FormFieldGrid>
            <FormField label="New Password">
              <input type="password" className={FIELD} placeholder="••••••••" />
            </FormField>
            <FormField label="Confirm New Password">
              <input type="password" className={FIELD} placeholder="••••••••" />
            </FormField>
          </FormFieldGrid>
          <div className="flex justify-end">
            <Button variant="default" size="sm">Update password</Button>
          </div>
        </div>

        <div className="mt-6 flex items-center justify-between border-t border-border-subtle pt-5">
          <div>
            <p className="text-sm font-semibold text-card-foreground">Two-factor authentication</p>
            <p className="text-sm text-muted-foreground">Require a code at sign-in for extra security.</p>
          </div>
          <Switch defaultChecked aria-label="Two-factor authentication" />
        </div>
      </Card>

      {/* Preferences */}
      <Card className={cardClass}>
        <h3 className="text-base font-bold text-card-foreground">Preferences</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Customize how the workspace looks and feels for you.
        </p>

        <div className="flex flex-col divide-y divide-border-subtle">
          <PreferenceRow
            title="Dark mode"
            description="Switch between the light and dark themes."
          />
          <PreferenceRow
            title="Compact tables"
            description="Reduce row height in data tables."
          />
          <PreferenceRow
            title="Email notifications"
            description="Follow-up reminders and the weekly digest."
            defaultChecked
          />
          <div className="flex items-center justify-between py-4">
            <div>
              <p className="text-sm font-semibold text-card-foreground">Language &amp; timezone</p>
              <p className="text-sm text-muted-foreground">English (US) · GMT+8 Manila</p>
            </div>
            <Button variant="outline" size="sm">Edit</Button>
          </div>
        </div>
      </Card>
    </div>
  );
}

function PreferenceRow({
  title,
  description,
  defaultChecked,
}: {
  title: string;
  description: string;
  defaultChecked?: boolean;
}) {
  return (
    <div className="flex items-center justify-between py-4">
      <div>
        <p className="text-sm font-semibold text-card-foreground">{title}</p>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      <Switch defaultChecked={defaultChecked} aria-label={title} />
    </div>
  );
}
