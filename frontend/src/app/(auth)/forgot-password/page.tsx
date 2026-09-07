"use client";

import { useState } from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { apiClient } from "@/core/api-client";
import { ThemeToggle } from "@/core/components/theme-toggle";

const schema = z.object({
  email: z.string().email("Enter a valid email address"),
});

type FormValues = z.infer<typeof schema>;

export default function ForgotPasswordPage() {
  const [sent, setSent] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  async function onSubmit(values: FormValues) {
    // The API answers identically whether or not the account exists, so the
    // page always lands on the same "check your inbox" state.
    await apiClient.post("/auth/forgot-password", values).catch(() => undefined);
    setSent(true);
  }

  return (
    <div className="relative flex min-h-screen items-center justify-center bg-muted px-4">
      <div className="absolute right-4 top-4">
        <ThemeToggle />
      </div>
      <div className="w-full max-w-sm rounded-card border border-border bg-card p-8 shadow-sm">
        <div className="mb-6 space-y-1 text-center">
          <h1 className="text-2xl font-semibold tracking-tight text-card-foreground">
            Forgot your password?
          </h1>
          <p className="text-sm text-muted-foreground">
            Enter your account email and we&apos;ll send a reset link.
          </p>
        </div>

        {sent ? (
          <div className="space-y-4">
            <div className="rounded-lg border border-border bg-muted px-3 py-3 text-sm text-card-foreground">
              If that email has an account, a reset link is on its way. Check
              your inbox (and spam folder).
            </div>
            <p className="text-center text-sm text-muted-foreground">
              <Link href="/login" className="font-semibold text-brand hover:underline">
                Back to sign in
              </Link>
            </p>
          </div>
        ) : (
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div className="space-y-1.5">
              <label htmlFor="email" className="text-sm font-medium text-card-foreground">
                Email
              </label>
              <Input
                id="email"
                type="email"
                autoComplete="email"
                placeholder="you@company.ph"
                aria-invalid={!!errors.email}
                {...register("email")}
              />
              {errors.email && (
                <p className="text-xs text-destructive">{errors.email.message}</p>
              )}
            </div>

            <Button type="submit" size="lg" className="h-10 w-full" disabled={isSubmitting}>
              {isSubmitting ? "Sending…" : "Send reset link"}
            </Button>

            <p className="text-center text-sm text-muted-foreground">
              Remembered it?{" "}
              <Link href="/login" className="font-semibold text-brand hover:underline">
                Sign in
              </Link>
            </p>
          </form>
        )}
      </div>
    </div>
  );
}
