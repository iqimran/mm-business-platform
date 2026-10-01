import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import { ApiError } from "./api-client";

/**
 * Maps an API failure onto a react-hook-form: 422 field errors go to their fields,
 * everything else (403, 409, network, ...) becomes the form's root error.
 */
export function applyApiErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
  /** API field name → form field name, when they differ. */
  aliases: Record<string, Path<T>> = {},
): void {
  if (error instanceof ApiError && error.status === 422) {
    let mapped = false;
    for (const [key, messages] of Object.entries(error.errors)) {
      const base = key.split(".")[0];
      const field = aliases[base] ?? fields.find((f) => f === base);
      if (field && messages[0]) {
        setError(field, { message: messages[0] });
        mapped = true;
      }
    }
    if (mapped) return;

    const first = Object.values(error.errors)[0]?.[0];
    setError("root" as Path<T>, { message: first ?? error.message });
    return;
  }

  setError("root" as Path<T>, { message: errorMessage(error) });
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.status === 403 ? "You do not have permission to do this." : error.message;
  }
  return "Something went wrong. Please try again.";
}
