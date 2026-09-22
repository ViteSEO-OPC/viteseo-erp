"use client";

import { useEffect, useState } from "react";
import { profileSchema, useValidatedForm } from "@/core/forms";
import { useAuth } from "@/core/auth/AuthProvider";
import { Avatar, Badge, Button, Card, Field, Input, Select } from "@/core/ui";
import { SignedInDevices } from "@/core/components/signed-in-devices";
import type { BadgeTone } from "@/core/ui";
import type { UserRole } from "@/core/types";

type ThemePreference = 'light' | 'dark' | 'system';

type ProfileFormData = {
  name: string;
  avatar: string;
  theme: ThemePreference;
};

const ROLE_TONES: Record<UserRole, BadgeTone> = {
  Admin: 'info',
  PM: 'accent',
  Member: 'neutral',
};

export function ProfileView() {
  const { user, updateProfile } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const {
    register,
    handleSubmit,
    reset,
    formState: { isSubmitting },
  } = useValidatedForm(profileSchema, {
    defaultValues: { name: '', avatar: '', theme: 'system' },
  });

  useEffect(() => {
    if (user) {
      const theme = (user.preferences?.theme as ThemePreference | undefined) ?? 'system';
      reset({ name: user.name, avatar: user.avatar ?? '', theme });
    }
  }, [user, reset]);

  const onSubmit = async (data: ProfileFormData) => {
    try {
      setError(null);
      setSaved(false);
      await updateProfile({
        name: data.name,
        avatar: data.avatar.trim() === '' ? null : data.avatar.trim(),
        preferences: { theme: data.theme },
      });
      setSaved(true);
    } catch {
      setError('Could not save your changes. Please try again.');
    }
  };

  const roleTone: BadgeTone = user?.role ? ROLE_TONES[user.role] : 'neutral';

  return (
    <div className="px-4 py-6 sm:px-6 lg:px-8">
      <h1 className="text-2xl font-bold text-ink sm:text-3xl">Profile</h1>

      {/* Identity card */}
      <Card className="mt-8 p-6">
        <div className="flex items-center gap-5">
          <Avatar name={user?.name ?? 'User'} src={user?.avatar} size={72} />
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-3">
              <h2 className="truncate text-2xl font-bold text-ink">{user?.name ?? '—'}</h2>
              {user?.role && <Badge tone={roleTone}>{user.role}</Badge>}
            </div>
            <p className="mt-1 truncate text-ink-soft">{user?.email ?? '—'}</p>
          </div>
        </div>
      </Card>

      {/* Edit form */}
      <Card className="mt-6 p-6">
        <h3 className="text-lg font-bold text-ink">Edit profile</h3>
        <form onSubmit={handleSubmit(onSubmit)} className="mt-6 space-y-5">
          {error && (
            <div className="rounded-lg border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger">
              {error}
            </div>
          )}
          {saved && (
            <div className="rounded-lg border border-success/30 bg-success-soft px-4 py-3 text-sm text-success">
              Your profile has been saved.
            </div>
          )}

          <Field label="Name">
            <Input type="text" autoComplete="name" placeholder="Your name" {...register('name')} />
          </Field>

          <Field label="Avatar URL">
            <Input
              type="url"
              autoComplete="off"
              placeholder="https://example.com/avatar.png"
              {...register('avatar')}
            />
          </Field>

          <Field label="Theme preference">
            <Select {...register('theme')}>
              <option value="light">Light</option>
              <option value="dark">Dark</option>
              <option value="system">System</option>
            </Select>
          </Field>

          <Button type="submit" size="lg" disabled={isSubmitting}>
            {isSubmitting ? 'Saving…' : 'Save changes'}
          </Button>
        </form>
      </Card>

      {/* Where this account is signed in */}
      <div className="mt-6">
        <SignedInDevices />
      </div>
    </div>
  );
}
