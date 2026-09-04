"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { apiClient } from "@/core/api-client";
import { ThemeToggle } from "@/core/components/theme-toggle";

const schema = z
  .object({
    password: z.string().min(8, "At least 8 characters"),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    message: "Passwords don't match",
    path: ["password_confirmation"],
  });

type FormValues = z.infer<typeof schema>;

function ResetPasswordForm() {
  const router = useRouter();
  const params = useSearchParams();
  const token = params.get("token") ?? "";
  const email = params.get("email") ?? "";
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const linkBroken = !token || !email;

  async function onSubmit(values: FormValues) {
    setFormError(null);
    try {
      await apiClient.post("/auth/reset-password", { token, email, ...values });
      toast.success("Password reset. Sign in with your new password.");
      router.replace("/login");
    } catch (err) {
      const message =
        err instanceof AxiosError
          ? (err.response?.data?.message ?? "That link may have expired. Request a new one.")
          : "Something went wrong. Try again.";
      setFormError(message);
    }
  }

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-muted px-4">
      <div className="absolute right-4 top-4">
        <ThemeToggle />
      </div>
      <div className="w-full max-w-sm rounded-card border border-border bg-card p-8 shadow-sm">
        <div className="mb-6 space-y-1 text-center">
          <h1 className="text-2xl font-semibold tracking-tight text-card-foreground">
            Set a new password
          </h1>
          <p className="text-sm text-muted-foreground">{email || "Reset your password"}</p>
        </div>

        {linkBroken ? (
          <div className="space-y-4">
            <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
              This reset link is incomplete. Open the link from your email, or
              request a new one.
            </div>
            <p className="text-center text-sm text-muted-foreground">
              <Link href="/forgot-password" className="font-semibold text-brand hover:underline">
                Request a new link
              </Link>
            </p>
          </div>
        ) : (
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div className="space-y-1.5">
              <label htmlFor="password" className="text-sm font-medium text-card-foreground">
                New password
              </label>
              <Input
                id="password"
                type="password"
                autoComplete="new-password"
                placeholder="••••••••"
                aria-invalid={!!errors.password}
                {...register("password")}
              />
              {errors.password && (
                <p className="text-xs text-destructive">{errors.password.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <label
                htmlFor="password_confirmation"
                className="text-sm font-medium text-card-foreground"
              >
                Confirm password
              </label>
              <Input
                id="password_confirmation"
                type="password"
                autoComplete="new-password"
                placeholder="••••••••"
                aria-invalid={!!errors.password_confirmation}
                {...register("password_confirmation")}
              />
              {errors.password_confirmation && (
                <p className="text-xs text-destructive">
                  {errors.password_confirmation.message}
                </p>
              )}
            </div>

            {formError && (
              <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                {formError}
              </div>
            )}

            <Button type="submit" size="lg" className="h-10 w-full" disabled={isSubmitting}>
              {isSubmitting ? "Saving…" : "Reset password"}
            </Button>
          </form>
        )}
      </div>
    </div>
  );
}

export default function ResetPasswordPage() {
  // useSearchParams needs a Suspense boundary in the app router.
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}
