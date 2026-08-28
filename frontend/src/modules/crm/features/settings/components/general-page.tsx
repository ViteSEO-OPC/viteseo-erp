"use client";

import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Switch } from "@/modules/crm/components/ui/switch";
import { useTableDensityPreference } from "@/modules/crm/components/ui/table-density";
import { FormField, FormFieldGrid } from "@/modules/crm/components/forms/form-field/form-field";
import { PendingBadge } from "@/modules/crm/components/shared/pending-badge/pending-badge";
import { ProfilePhotoField } from "@/modules/crm/features/profile/components/profile-photo-field";
import { useEffect, useState } from "react";
import { useAuth } from "@/modules/crm/providers/auth-provider";
import { useTheme } from "@/modules/crm/providers/theme-provider";
import { useProfile, useUpdateProfile } from "@/modules/crm/features/profile/hooks/use-profile";
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
 *
 * Name and phone are editable and persist through PATCH /me — the SAME `useUpdateProfile` hook the
 * BD self-service page uses, so the two self-service forms cannot drift apart. That mattered more
 * than it looks: /zerem/profile is `allowedRoles: ["crm_bd"]` and /zerem/settings is
 * `minRole: "crm_admin"`, so the two audiences are disjoint and this page is the ONLY place an
 * Admin or Super Admin can edit their own details. Its Save button previously did nothing.
 *
 * Email and role are read-only on purpose, not unfinished: PATCH /me accepts `name` and `phone`
 * and nothing else, because the email IS the central Vite SEO Systems identity. Rendering email as
 * an open input promised a save the platform will never honour.
 *
 * Password and 2FA are NOT deferred work — authentication is owned by the central account that
 * signs a person in to TeamOra, Zerem and Sprintly, so this page only states where they are
 * managed. Preferences are live, except Language & timezone (see the row itself).
 */
export function GeneralPage() {
  const { user } = useAuth();
  // Display-only preference, stored per browser — the same shape as the theme. No API call.
  const { density, setDensity } = useTableDensityPreference();
  // The one theme implementation, shared with the topbar toggle. No second theme system.
  const { theme, setTheme } = useTheme();
  const role = highestRole(user?.roles);
  const roleLabel = role ? ROLE_LABELS[role] : "—";

  // `useProfile` (GET /me) is the authoritative source: it carries `phone`, which the cached auth
  // user does not. Mirrors bd-settings-page.tsx exactly — same hooks, same seeding, same dirty
  // rule — so the BD and Admin self-service forms behave identically.
  const { data: profile } = useProfile();
  const updateProfile = useUpdateProfile();

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");

  // Seed once the profile resolves, and re-seed after a save (useUpdateProfile's setQueryData makes
  // the saved values the new baseline, which is what clears `dirty`).
  useEffect(() => {
    if (profile) {
      // Seed from async query data (the auth-provider convention); this is the intended
      // reset-to-baseline, not the cascading-render anti-pattern the rule targets.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setName(profile.name);
      setPhone(profile.phone ?? "");
    }
  }, [profile]);

  const dirty = !!profile && (name !== profile.name || phone !== (profile.phone ?? ""));
  const canSave = dirty && name.trim().length > 0 && !updateProfile.isPending;

  function handleSave() {
    updateProfile.mutate({ name: name.trim(), phone: phone.trim() || null });
  }

  function handleCancel() {
    if (profile) {
      setName(profile.name);
      setPhone(profile.phone ?? "");
    }
  }

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

        <div className="mb-6">
          <ProfilePhotoField initials={initialsOf(user?.name)}>
            <p className="text-sm font-semibold text-card-foreground">Profile photo</p>
            <p className="mb-2 text-xs text-muted-foreground">PNG or JPG, up to 4 MB.</p>
          </ProfilePhotoField>
        </div>

        <div className="flex flex-col gap-5">
          <FormFieldGrid>
            <FormField label="Full Name">
              <input
                type="text"
                className={FIELD}
                value={name}
                onChange={(e) => setName(e.target.value)}
                disabled={updateProfile.isPending}
              />
            </FormField>
            <FormField label="Role">
              <input type="text" className={FIELD} defaultValue={roleLabel} readOnly />
            </FormField>
          </FormFieldGrid>
          <FormFieldGrid>
            {/* Read-only like Role beside it: PATCH /me accepts only `name` and `phone`, and the
                email IS the central Vite SEO Systems identity. It was previously an open input,
                which promised a save that could never happen. */}
            <FormField label="Email Address">
              <input
                type="email"
                className={FIELD}
                value={profile?.email ?? user?.email ?? ""}
                readOnly
              />
            </FormField>
            <FormField label="Phone">
              <input
                type="tel"
                className={FIELD}
                placeholder="+63 917 000 0000"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                disabled={updateProfile.isPending}
              />
            </FormField>
          </FormFieldGrid>
        </div>

        <div className="mt-6 flex items-center justify-end gap-3">
          <Button
            variant="outline"
            size="sm"
            onClick={handleCancel}
            disabled={!dirty || updateProfile.isPending}
          >
            Cancel
          </Button>
          <Button variant="default" size="sm" onClick={handleSave} disabled={!canSave}>
            {updateProfile.isPending ? "Saving…" : "Save changes"}
          </Button>
        </div>
      </Card>

      {/* Login & security */}
      <Card className={cardClass}>
        <h3 className="text-base font-bold text-card-foreground">Login &amp; security</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Sign-in is handled by your Vite SEO Systems account, not by Zerem.
        </p>

        <div className="rounded-lg border border-border bg-muted/40 px-4 py-3">
          <p className="text-sm font-semibold text-card-foreground">Password</p>
          <p className="mt-1 text-sm text-muted-foreground">
            One account signs you in to TeamOra, Zerem and Sprintly, so your password is managed
            centrally and cannot be changed from here. Contact your system administrator to
            change it.
          </p>
        </div>

        <div className="mt-6 flex items-center justify-between border-t border-border-subtle pt-5">
          <div>
            <p className="text-sm font-semibold text-card-foreground">Two-factor authentication</p>
            <p className="text-sm text-muted-foreground">
              Not in use. Sign-in protection is managed by your Vite SEO Systems account.
            </p>
          </div>
          <Switch
            checked={false}
            disabled
            aria-label="Two-factor authentication — managed by your Vite SEO Systems account"
          />
        </div>
      </Card>

      {/* Preferences */}
      <Card className={cardClass}>
        <h3 className="text-base font-bold text-card-foreground">Preferences</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Customize how the workspace looks and feels for you.
        </p>

        <div className="flex flex-col divide-y divide-border-subtle">
          {/* Wired to the ONE theme implementation the topbar toggle already drives. It was
              previously uncontrolled, so it rendered OFF even while the app was in dark mode and
              did nothing when clicked — the exact "inert control asserting a state" this file's
              own PreferenceRow docblock warns against. */}
          <PreferenceRow
            title="Dark mode"
            description="Switch between the light and dark themes."
            checked={theme === "dark"}
            onCheckedChange={(next) => setTheme(next ? "dark" : "light")}
          />
          <PreferenceRow
            title="Compact tables"
            description="Reduce row height in data tables."
            checked={density === "compact"}
            onCheckedChange={(next) => setDensity(next ? "compact" : "normal")}
          />
          <PreferenceRow
            title="Email notifications"
            description="Reply alerts to your work email. Configured per workspace, not per user."
            pendingReason="There is no per-user email preference yet — reply alerts are switched on per environment, and nothing here changes what is sent"
          />
          {/* Honest pending row, following the Email-notifications row above: there is no per-user
              locale or timezone to edit. PATCH /me accepts only `name` and `phone`, and the
              business timezone is an app-wide Asia/Manila rule, not a preference. The Edit button
              is kept but inert rather than removed, so the row still reads as a future setting. */}
          <div className="flex items-center justify-between py-4">
            <div>
              <p className="flex items-center gap-2 text-sm font-semibold text-card-foreground">
                Language &amp; timezone
                <PendingBadge reason="There is no per-user language or timezone setting — the workspace runs on English (US) and the Asia/Manila business timezone for everyone" />
              </p>
              <p className="text-sm text-muted-foreground">English (US) · GMT+8 Manila</p>
            </div>
            <Button variant="outline" size="sm" disabled>
              Edit
            </Button>
          </div>
        </div>
      </Card>
    </div>
  );
}

/**
 * One preference row.
 *
 * `pendingReason` marks a preference whose backend does not exist yet: the switch is rendered
 * OFF and disabled, with the shared PendingBadge carrying the reason. That combination is the
 * point — a hardcoded `defaultChecked` previously showed Email notifications as ON, which read as
 * "this preference is saved and enabled" when nothing stores or reads it. An inert control must
 * never assert a state.
 */
function PreferenceRow({
  title,
  description,
  defaultChecked,
  checked,
  onCheckedChange,
  pendingReason,
}: {
  title: string;
  description: string;
  defaultChecked?: boolean;
  /** Controlled mode — a preference that is actually wired to something. */
  checked?: boolean;
  onCheckedChange?: (checked: boolean) => void;
  pendingReason?: string;
}) {
  return (
    <div className="flex items-center justify-between py-4">
      <div>
        <p className="flex items-center gap-2 text-sm font-semibold text-card-foreground">
          {title}
          {pendingReason ? <PendingBadge reason={pendingReason} /> : null}
        </p>
        <p className="text-sm text-muted-foreground">{description}</p>
      </div>
      {pendingReason ? (
        <Switch checked={false} disabled aria-label={`${title} — not available yet`} />
      ) : onCheckedChange ? (
        <Switch checked={checked} onCheckedChange={onCheckedChange} aria-label={title} />
      ) : (
        <Switch defaultChecked={defaultChecked} aria-label={title} />
      )}
    </div>
  );
}
