"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { Button } from "@/components/ui/button";
import { useAppName } from "@/features/branding/hooks";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { useLogin } from "../hooks";
import { loginSchema, type LoginValues } from "../schemas";

export function LoginForm() {
  const appName = useAppName();
  const loginMutation = useLogin();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });

  const onSubmit = handleSubmit(async (values) => {
    try {
      await loginMutation.mutateAsync(values);
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        const emailError = error.errors.email?.[0];
        const passwordError = error.errors.password?.[0];
        if (emailError) setError("email", { message: emailError });
        if (passwordError) setError("password", { message: passwordError });
        if (!emailError && !passwordError) setError("root", { message: error.message });
        return;
      }

      setError("root", {
        message: error instanceof ApiError ? error.message : "Something went wrong. Please try again.",
      });
    }
  });

  const busy = isSubmitting || loginMutation.isPending;

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <p className="text-lg font-semibold tracking-tight">{appName}</p>
        <CardTitle className="text-xl">Sign in</CardTitle>
        <CardDescription>Use your business platform account.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          {errors.root?.message ? (
            <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {errors.root.message}
            </p>
          ) : null}

          <div className="flex flex-col gap-2">
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              autoComplete="username"
              autoFocus
              aria-invalid={errors.email ? true : undefined}
              aria-describedby={errors.email ? "email-error" : undefined}
              {...register("email")}
            />
            {errors.email ? (
              <p id="email-error" className="text-sm text-destructive">
                {errors.email.message}
              </p>
            ) : null}
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="password">Password</Label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              aria-invalid={errors.password ? true : undefined}
              aria-describedby={errors.password ? "password-error" : undefined}
              {...register("password")}
            />
            {errors.password ? (
              <p id="password-error" className="text-sm text-destructive">
                {errors.password.message}
              </p>
            ) : null}
          </div>

          <Button type="submit" size="lg" disabled={busy} className="w-full">
            {busy ? "Signing in…" : "Sign in"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
