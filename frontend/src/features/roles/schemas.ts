import { z } from "zod";

export const roleSchema = z.object({
  name: z.string().trim().min(1, "Name is required.").max(100, "Name must be at most 100 characters."),
  description: z.string().trim().max(255, "Description must be at most 255 characters."),
  permissions: z.array(z.string()),
});

export type RoleValues = z.infer<typeof roleSchema>;
