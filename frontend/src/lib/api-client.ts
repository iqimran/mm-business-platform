import { API_BASE_URL, API_ORIGIN } from "@/config/env";
import type { ApiFailure, ApiSuccess } from "@/types/api";

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "ApiError";
  }
}

type RequestOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
};

const XSRF_COOKIE = "XSRF-TOKEN";
const CSRF_MISMATCH = 419;

function readXsrfToken(): string | null {
  const cookie = document.cookie.split("; ").find((c) => c.startsWith(`${XSRF_COOKIE}=`));
  return cookie ? decodeURIComponent(cookie.slice(XSRF_COOKIE.length + 1)) : null;
}

/** Sanctum SPA auth: obtain the CSRF cookie before state-changing requests. */
async function fetchCsrfCookie(): Promise<void> {
  await fetch(`${API_ORIGIN}/sanctum/csrf-cookie`, { credentials: "include" });
}

/**
 * Calls the Laravel API with the session cookie (HttpOnly, never readable here)
 * and the CSRF header. Returns `data` of the response contract or throws ApiError.
 */
export async function apiRequest<T>(path: string, { method = "GET", body }: RequestOptions = {}, retried = false): Promise<T> {
  const mutating = method !== "GET";

  if (mutating && !readXsrfToken()) {
    await fetchCsrfCookie();
  }

  const isForm = body instanceof FormData;
  const headers: Record<string, string> = { Accept: "application/json" };
  // FormData sets its own multipart boundary header.
  if (body !== undefined && !isForm) headers["Content-Type"] = "application/json";
  const xsrf = mutating ? readXsrfToken() : null;
  if (xsrf) headers["X-XSRF-TOKEN"] = xsrf;

  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      method,
      headers,
      credentials: "include",
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });
  } catch {
    throw new ApiError("Cannot reach the server. Please check your connection.", 0);
  }

  // Expired CSRF token: refresh it once and retry.
  if (response.status === CSRF_MISMATCH && mutating && !retried) {
    await fetchCsrfCookie();
    return apiRequest<T>(path, { method, body }, true);
  }

  const payload = (await response.json().catch(() => null)) as ApiSuccess<T> | ApiFailure | null;

  if (!response.ok || !payload || payload.success !== true) {
    const failure = payload && payload.success === false ? payload : null;
    throw new ApiError(failure?.message ?? "Something went wrong. Please try again.", response.status, failure?.errors ?? {});
  }

  return payload.data;
}

/**
 * Downloads a file from the API (e.g. report exports) with the session cookie and saves it
 * under the server-provided file name. API errors are raised as ApiError like JSON calls.
 */
export async function apiDownload(path: string, fallbackName: string): Promise<void> {
  let response: Response;
  try {
    response = await fetch(`${API_BASE_URL}${path}`, { credentials: "include", headers: { Accept: "*/*" } });
  } catch {
    throw new ApiError("Cannot reach the server. Please check your connection.", 0);
  }

  if (!response.ok) {
    const payload = (await response.json().catch(() => null)) as ApiFailure | null;
    const first = payload?.errors ? Object.values(payload.errors)[0]?.[0] : undefined;
    throw new ApiError(first ?? payload?.message ?? "The download failed.", response.status, payload?.errors ?? {});
  }

  const disposition = response.headers.get("Content-Disposition") ?? "";
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
  const filename = match ? decodeURIComponent(match[1]) : fallbackName;

  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
