import { ApiError, apiRequest } from "@/lib/api-client";

export type AuthUser = {
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
};

export type BranchSummary = {
  id: string;
  code: string;
  name: string;
};

/** Current session. Permissions/branches are for UI decisions only; the API enforces access. */
export type Session = {
  user: AuthUser;
  permissions: string[];
  branches: BranchSummary[];
};

export type LoginInput = {
  email: string;
  password: string;
};

/** Returns null when not logged in. */
export async function fetchSession(): Promise<Session | null> {
  try {
    return await apiRequest<Session>("/auth/me");
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
}

export function login(input: LoginInput) {
  return apiRequest<{ user: AuthUser }>("/auth/login", { method: "POST", body: input });
}

export function logout() {
  return apiRequest<Record<string, never>>("/auth/logout", { method: "POST" });
}
