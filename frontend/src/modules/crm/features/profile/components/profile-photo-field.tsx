"use client";

import { useRef, useState, type ReactNode } from "react";
import { notify as toast } from "@/core/lib/notify";
import { Button } from "@/modules/crm/components/ui/button";
import { AvatarInitials } from "@/modules/crm/components/shared/avatar-initials/avatar-initials";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import {
  useProfile,
  useRemoveProfilePhoto,
  useUploadProfilePhoto,
} from "@/modules/crm/features/profile/hooks/use-profile";

/** 4 MB, matching the copy beside the avatar and the server's `max:4096` rule. */
const MAX_PHOTO_BYTES = 4 * 1024 * 1024;

/**
 * The signed-in user's own profile photo: the avatar plus its Change / Remove controls.
 *
 * ONE component for every surface that can edit the photo — the admin Settings page and the BD
 * self-service Profile page both mount this. They previously had different avatar markup and only
 * one of them had buttons; sharing the interactive part is what stops the two drifting into two
 * subtly different upload flows.
 *
 * The surrounding text differs between the two pages (a field label on Settings, the user's name
 * and email on Profile), so that stays with each caller as `children`. Everything that could go
 * wrong — validation, in-flight state, the confirm step, cache seeding — lives here once.
 */
export function ProfilePhotoField({
  initials,
  children,
  size = "md",
}: {
  /** What to show when there is no photo. Passed in so a page can track its own live-edited name. */
  initials: string;
  children?: ReactNode;
  size?: "sm" | "md";
}) {
  const { data: profile } = useProfile();
  const upload = useUploadProfilePhoto();
  const remove = useRemoveProfilePhoto();
  const fileRef = useRef<HTMLInputElement>(null);
  const [confirmRemove, setConfirmRemove] = useState(false);

  const busy = upload.isPending || remove.isPending;

  function handlePicked(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    // Reset immediately so picking the SAME file again still fires a change event.
    event.target.value = "";

    if (!file) return;

    // Caught here only to avoid spending an upload on a file the server will certainly refuse.
    // The server remains the authority; its message is what surfaces for every other case.
    if (file.size > MAX_PHOTO_BYTES) {
      toast.error("That photo is too large", "Choose a PNG or JPG of 4 MB or less.");
      return;
    }

    upload.mutate(file);
  }

  return (
    <div className="flex items-center gap-4">
      <AvatarInitials src={profile?.avatarUrl} initials={initials} size={size} />
      <div>
        {children}
        <div className="flex gap-2">
          <input
            ref={fileRef}
            type="file"
            accept="image/png,image/jpeg"
            className="hidden"
            onChange={handlePicked}
          />
          <Button
            variant="default"
            size="sm"
            disabled={busy}
            onClick={() => fileRef.current?.click()}
          >
            {upload.isPending ? "Uploading…" : "Change photo"}
          </Button>
          {/* Offered only when there is something to remove — the button did nothing for a user
              with no photo, and a disabled control says that more honestly. */}
          <Button
            variant="outline"
            size="sm"
            disabled={busy || !profile?.hasPhoto}
            onClick={() => setConfirmRemove(true)}
          >
            {remove.isPending ? "Removing…" : "Remove"}
          </Button>
        </div>
      </div>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={setConfirmRemove}
        title="Remove your profile photo?"
        description="Your avatar will go back to your initials. You can upload a new photo at any time."
        confirmLabel="Remove photo"
        destructive
        loading={remove.isPending}
        onConfirm={() => {
          remove.mutate(undefined, { onSuccess: () => setConfirmRemove(false) });
        }}
      />
    </div>
  );
}
