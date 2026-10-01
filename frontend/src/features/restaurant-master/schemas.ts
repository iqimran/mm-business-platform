import { z } from "zod";
import type { MasterResource } from "./config";

export type FormValues = Record<string, string | boolean>;

/** Same format the API accepts: up to 12 digits and 2 decimals, never a float. */
const MONEY = /^(0|[1-9]\d{0,11})(\.\d{1,2})?$/;

export function buildSchema(resource: MasterResource) {
  const shape: Record<string, z.ZodType<string | boolean, string | boolean>> = { is_active: z.boolean() };

  for (const field of resource.fields) {
    let rule = z.string().trim();

    if (field.type === "money") {
      shape[field.name] = rule
        .min(1, `${field.label} is required.`)
        .regex(MONEY, `${field.label} must be an amount with at most 2 decimal places, e.g. 250.00.`)
        .refine((v) => !MONEY.test(v) || /[1-9]/.test(v), `${field.label} must be greater than zero.`);
      continue;
    }
    if (field.type === "number") {
      shape[field.name] = rule.refine(
        (v) => v === "" || (/^\d+$/.test(v) && Number(v) <= field.max),
        `${field.label} must be a whole number between 0 and ${field.max}.`,
      );
      continue;
    }

    rule = rule.max(field.max, `${field.label} must be at most ${field.max} characters.`);
    if (field.required) rule = rule.min(1, field.type === "category" ? `Select a ${field.label.toLowerCase()}.` : `${field.label} is required.`);
    shape[field.name] = rule;
  }

  return z.object(shape);
}

/** Form values → API payload: empty optional text is sent as null (clears it); numbers as integers. */
export function toPayload(resource: MasterResource, values: FormValues): Record<string, unknown> {
  const types = Object.fromEntries(resource.fields.map((f) => [f.name, f.type]));

  return Object.fromEntries(
    Object.entries(values)
      .filter(([key, value]) => !(types[key] === "number" && value === ""))
      .map(([key, value]) => {
        if (typeof value !== "string") return [key, value];
        const trimmed = value.trim();
        if (types[key] === "number") return [key, Number(trimmed)];
        return [key, trimmed === "" ? null : trimmed];
      }),
  );
}
