"use client";

import { useEffect, useState } from "react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { FormField, FormFieldGrid } from "@/modules/crm/components/forms/form-field/form-field";
import { ProfilePhotoField } from "@/modules/crm/features/profile/components/profile-photo-field";
import { ROLE_LABELS } from "@/modules/crm/lib/rbac";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { useProfile, useUpdateProfile } from "@/modules/crm/features/profile/hooks/use-profile";

const cardClass = "border border-border bg-card p-6 shadow-card";
// Read-only presentation of the FIELD input — visually muted + non-interactive.
const READONLY_FIELD = `${FIELD} cursor-not-allowed bg-muted/50 text-muted-foreground`;

function initialsOf(name: string): string {
  if (!name.trim()) return "U";
  return name
    .split(" ")
    .map((w) => w[0])
    .filter(Boolean)
    .join("")
    .slice(0, 2)
    .toUpperCase();
}

/**
 * Business Development Manager self-service Settings — the signed-in BD's own profile only.
 * Editable: Full Name, Phone, profile photo. Read-only: Email (login identity) and Role. There is
 * intentionally NO user management, roles/permissions, SMTP, API keys, integrations, or Gmail here
 * — those are the separate Admin Settings module.
 *
 * The photo control is the SAME {@link ProfilePhotoField} the admin Settings page mounts, which is
 * the point: a BD cannot reach /zerem/settings (it is `minRole: crm_admin`), so without it here the
 * feature would be admin-only. It is an explicit upload and nothing more — the CRM still has no
 * per-user Gmail integration, so no avatar on this page is synced from a Google account.
 */
export function BdSettingsPage() {
  const { data: profile, isLoading } = useProfile();
  const updateProfile = useUpdateProfile();

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");

  // Seed the editable fields once the profile resolves, and re-seed after a save (setQueryData
  // updates `profile` to the saved values, which become the new baseline).
  useEffect(() => {
    if (profile) {
      // Seed once from async query data (matches the auth-provider convention); this is the
      // intended reset-to-baseline, not the cascading-render anti-pattern the rule targets.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setName(profile.name);
      setPhone(profile.phone ?? "");
    }
  }, [profile]);

  const roleLabel = profile?.role ? ROLE_LABELS[profile.role] : "—";
  const dirty =
    !!profile &&
    (name !== profile.name || phone !== (profile.phone ?? ""));
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
        <h2 className="text-2xl font-bold text-foreground">Settings</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage your personal profile.
        </p>
      </header>

      <Card className={cardClass}>
        <h3 className="text-base font-bold text-card-foreground">Profile information</h3>
        <p className="mb-5 text-sm text-muted-foreground">
          Your details as they appear across the workspace.
        </p>

        {isLoading || !profile ? (
          <div className="flex items-center gap-3 py-8 text-sm text-muted-foreground">
            <span className="h-5 w-5 animate-spin rounded-full border-2 border-teal-400 border-t-transparent" />
            Loading your profile…
          </div>
        ) : (
          <>
            <div className="mb-6">
              <ProfilePhotoField initials={initialsOf(name)}>
                <p className="text-sm font-semibold text-card-foreground">
                  {name || "Your account"}
                </p>
                <p className="mb-2 text-xs text-muted-foreground">{profile.email}</p>
              </ProfilePhotoField>
            </div>

            <div className="flex flex-col gap-5">
              <FormFieldGrid>
                <FormField label="Full Name" required>
                  <input
                    type="text"
                    className={FIELD}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    placeholder="Your full name"
                  />
                </FormField>
                <FormField label="Role">
                  <input
                    type="text"
                    className={READONLY_FIELD}
                    value={roleLabel}
                    readOnly
                    aria-readonly
                    tabIndex={-1}
                  />
                </FormField>
              </FormFieldGrid>
              <FormFieldGrid>
                <FormField label="Email Address">
                  <input
                    type="email"
                    className={READONLY_FIELD}
                    value={profile.email}
                    readOnly
                    aria-readonly
                    tabIndex={-1}
                  />
                </FormField>
                <FormField label="Phone" optional>
                  <input
                    type="tel"
                    className={FIELD}
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    placeholder="+63 917 000 0000"
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
              <Button
                variant="default"
                size="sm"
                onClick={handleSave}
                disabled={!canSave}
              >
                {updateProfile.isPending ? "Saving…" : "Save changes"}
              </Button>
            </div>
          </>
        )}
      </Card>
    </div>
  );
}
