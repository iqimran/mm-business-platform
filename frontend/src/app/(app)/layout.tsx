"use client";

import { useRouter } from "next/navigation";
import { useEffect, type ReactNode } from "react";
import { AppShell } from "@/components/layout/app-shell";
import { useSession } from "@/features/auth/hooks";

/**
 * Layout for signed-in pages. The guard only drives navigation;
 * every API call is still authenticated and authorized by the backend.
 */
export default function AuthenticatedLayout({ children }: { children: ReactNode }) {
  const router = useRouter();
  const { data: session, isPending, isError } = useSession();

  useEffect(() => {
    if (!isPending && !isError && !session) router.replace("/login");
  }, [isPending, isError, session, router]);

  if (isError) {
    return <p className="m-auto text-sm text-destructive">Could not load your session. Please refresh the page.</p>;
  }

  if (isPending || !session) {
    return <p className="m-auto text-sm text-muted-foreground">Loading…</p>;
  }

  return <AppShell session={session}>{children}</AppShell>;
}
