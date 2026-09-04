"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AxiosError } from "axios";
import { notify as toast } from "@/core/lib/notify";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useAuth } from "@/core/auth-context";
import { resolveLandingHref } from "@/core/access";
import { ThemeToggle } from "@/core/components/theme-toggle";

const loginSchema = z.object({
  email: z.string().email("Enter a valid email address"),
  password: z.string().min(1, "Password is required"),
});

type LoginForm = z.infer<typeof loginSchema>;

export default function LoginPage() {
  const router = useRouter();
  const { login, user, roles, permissions, loading } = useAuth();
  const [formError, setFormError] = useState<string | null>(null);

  // Already signed in? Skip the form and go to the right place: straight into
  // the module when the user can access exactly one, or the launcher (/home)
  // when they can access more than one.
  useEffect(() => {
    if (!loading && user) {
      router.replace(resolveLandingHref(roles as unknown as string[], permissions));
    }
  }, [loading, user, roles, permissions, router]);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ resolver: zodResolver(loginSchema) });

  async function onSubmit(values: LoginForm) {
    setFormError(null);
    try {
      const res = await login(values.email, values.password);
      toast.success(`Welcome back, ${res.user.name.split(" ")[0]}!`);
      // Permission-based routing: land straight in the only accessible module,
      // or on the launcher (/home) when the user can reach more than one.
      router.replace(
        resolveLandingHref(res.roles as unknown as string[], res.user.permissions),
      );
    } catch (err) {
      const message =
        err instanceof AxiosError
          ? (err.response?.data?.message ?? "Something went wrong. Try again.")
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
            Vite SEO Systems
          </h1>
          <p className="text-sm text-muted-foreground">
            Sign in to your account
          </p>
        </div>

        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
          <div className="space-y-1.5">
            <label
              htmlFor="email"
              className="text-sm font-medium text-card-foreground"
            >
              Email
            </label>
            <Input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="you@viteseo.ph"
              aria-invalid={!!errors.email}
              {...register("email")}
            />
            {errors.email && (
              <p className="text-xs text-destructive">{errors.email.message}</p>
            )}
          </div>

          <div className="space-y-1.5">
            <label
              htmlFor="password"
              className="text-sm font-medium text-card-foreground"
            >
              Password
            </label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              aria-invalid={!!errors.password}
              {...register("password")}
            />
            {errors.password && (
              <p className="text-xs text-destructive">
                {errors.password.message}
              </p>
            )}
          </div>

          {formError && (
            <div className="rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {formError}
            </div>
          )}

          <div className="text-right">
            <Link
              href="/forgot-password"
              className="text-sm font-medium text-brand hover:underline"
            >
              Forgot password?
            </Link>
          </div>

          <Button
            type="submit"
            size="lg"
            className="h-10 w-full"
            disabled={isSubmitting}
          >
            {isSubmitting ? "Signing in…" : "Sign in"}
          </Button>
        </form>
      </div>
    </div>
  );
}
