"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { LoginForm } from "@/features/auth/components/login-form";
import { useSession } from "@/features/auth/hooks";

export default function LoginPage() {
  const router = useRouter();
  const { data: session } = useSession();

  // Already signed in: go straight to the app.
  useEffect(() => {
    if (session) router.replace("/dashboard");
  }, [session, router]);

  return (
    <main className="flex flex-1 items-center justify-center bg-muted/40 px-4 py-12">
      <LoginForm />
    </main>
  );
}
