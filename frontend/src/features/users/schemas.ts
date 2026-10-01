import { z } from "zod";

/** Mirrors the API password policy (min 10, upper + lower case, a number); the API is authoritative. */
export const passwordRule = z
  .string()
  .min(10, "At least 10 characters.")
  .max(255)
  .refine((v) => /[a-z]/.test(v) && /[A-Z]/.test(v), "Use upper- and lower-case letters.")
  .refine((v) => /\d/.test(v), "Include a number.");

const emailRule = z.string().trim().min(1, "Email is required.").pipe(z.email("Enter a valid email address."));

export const newUserSchema = z.object({
  name: z.string().trim().min(1, "Name is required.").max(255),
  email: emailRule,
  password: passwordRule,
  is_active: z.boolean(),
  role_ids: z.array(z.string()),
  branch_ids: z.array(z.string()),
});

export const editUserSchema = z.object({
  name: z.string().trim().min(1, "Name is required.").max(255),
  email: emailRule,
  password: z.union([z.literal(""), passwordRule]),
  is_active: z.boolean(),
});

export type NewUserValues = z.infer<typeof newUserSchema>;
export type EditUserValues = z.infer<typeof editUserSchema>;
