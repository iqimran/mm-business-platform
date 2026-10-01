"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Controller, useForm } from "react-hook-form";
import { z } from "zod";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { applyApiErrors } from "@/lib/form-errors";
import type { Branch } from "../api";
import { useSaveBranch } from "../hooks";

const schema = z.object({
  code: z
    .string()
    .trim()
    .min(1, "Code is required.")
    .max(20, "Code must be at most 20 characters.")
    .regex(/^[A-Za-z0-9][A-Za-z0-9_-]*$/, "Use letters, numbers, - or _ (e.g. DHK-01)."),
  name: z.string().trim().min(1, "Name is required.").max(150, "Name must be at most 150 characters."),
  phone: z.string().trim().max(30, "Phone must be at most 30 characters."),
  email: z.string().trim().max(255).refine((v) => v === "" || z.email().safeParse(v).success, "Enter a valid email address."),
  address: z.string().trim().max(1000, "Address must be at most 1000 characters."),
  is_active: z.boolean(),
});

type Values = z.infer<typeof schema>;

export function BranchForm({ branch, onDone }: { branch?: Branch; onDone: () => void }) {
  const save = useSaveBranch();
  const prefix = `branch-${branch?.id ?? "new"}`;
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      code: branch?.code ?? "",
      name: branch?.name ?? "",
      phone: branch?.phone ?? "",
      email: branch?.email ?? "",
      address: branch?.address ?? "",
      is_active: branch?.is_active ?? true,
    },
  });

  const submit = handleSubmit(async (v) => {
    try {
      await save.mutateAsync({
        id: branch?.id,
        input: { ...v, code: v.code.toUpperCase(), phone: v.phone || null, email: v.email || null, address: v.address || null },
      });
      onDone();
    } catch (e) {
      applyApiErrors(e, setError, ["code", "name", "phone", "email", "address", "is_active"]);
    }
  });

  const field = (name: "code" | "name" | "phone" | "email", label: string, extra?: { required?: boolean; type?: string; hint?: string }) => (
    <div className="flex flex-col gap-2">
      <Label htmlFor={`${prefix}-${name}`}>
        {label}
        {extra?.required ? <span aria-hidden className="text-destructive">*</span> : null}
      </Label>
      <Input id={`${prefix}-${name}`} type={extra?.type ?? "text"} aria-invalid={errors[name] ? true : undefined} {...register(name)} />
      {extra?.hint && !errors[name] ? <p className="text-xs text-muted-foreground">{extra.hint}</p> : null}
      <FieldError id={`${prefix}-${name}-error`} message={errors[name]?.message} />
    </div>
  );

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
      <FormAlert message={errors.root?.message} />
      <div className="grid gap-4 sm:grid-cols-2">
        {field("code", "Code", { required: true, hint: "Short unique code, stored in uppercase (e.g. DHK-01)." })}
        {field("name", "Name", { required: true })}
        {field("phone", "Phone", { type: "tel" })}
        {field("email", "Email", { type: "email" })}
        <div className="flex flex-col gap-2 sm:col-span-2">
          <Label htmlFor={`${prefix}-address`}>Address</Label>
          <Textarea id={`${prefix}-address`} rows={2} aria-invalid={errors.address ? true : undefined} {...register("address")} />
          <FieldError id={`${prefix}-address-error`} message={errors.address?.message} />
        </div>
      </div>
      <Controller
        control={control}
        name="is_active"
        render={({ field: f }) => (
          <div className="flex items-center gap-2">
            <Checkbox id={`${prefix}-active`} checked={f.value} onCheckedChange={(c) => f.onChange(c)} />
            <Label htmlFor={`${prefix}-active`} className="font-normal">
              Active
            </Label>
          </div>
        )}
      />
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : branch ? "Save" : "Add branch"}
        </Button>
      </div>
    </form>
  );
}
