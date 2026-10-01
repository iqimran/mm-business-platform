"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { fetchSession, login, logout } from "./api";

export const sessionQueryKey = ["auth", "session"] as const;

export function useSession() {
  return useQuery({
    queryKey: sessionQueryKey,
    queryFn: fetchSession,
    retry: false,
    staleTime: 30_000,
    // Permission/role changes made by an admin show up when the user returns to the tab.
    refetchOnWindowFocus: true,
  });
}

/**
 * Permission checks for showing/hiding UI. Never a security boundary: the API authorizes every request.
 */
export function usePermissions() {
  const { data: session } = useSession();
  const granted = new Set(session?.permissions ?? []);

  return {
    can: (permission: string) => granted.has(permission),
    granted,
  };
}

export function useLogin() {
  const queryClient = useQueryClient();
  const router = useRouter();

  return useMutation({
    mutationFn: login,
    onSuccess: async () => {
      // Load permissions/branches for the new session before entering the app.
      await queryClient.fetchQuery({ queryKey: sessionQueryKey, queryFn: fetchSession });
      router.replace("/dashboard");
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();
  const router = useRouter();

  return useMutation({
    mutationFn: logout,
    onSettled: () => {
      // Drop all cached data from the previous session.
      queryClient.clear();
      router.replace("/login");
    },
  });
}
