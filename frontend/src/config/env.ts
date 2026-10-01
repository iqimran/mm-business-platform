/** Laravel API base URL, e.g. http://localhost:8080/api/v1 (set via NEXT_PUBLIC_API_BASE_URL). */
export const API_BASE_URL = (process.env.NEXT_PUBLIC_API_BASE_URL ?? "http://localhost:8080/api/v1").replace(/\/+$/, "");

/** Origin of the API, where Sanctum serves /sanctum/csrf-cookie. */
export const API_ORIGIN = new URL(API_BASE_URL).origin;
