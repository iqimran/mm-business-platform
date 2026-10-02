"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Building } from "lucide-react";
import { useForm } from "react-hook-form";
import { useNotify } from "@/components/common/notifications";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";
import type { BusinessProfile } from "../api";
import { useBusinessProfiles, useSaveBusinessProfile } from "../hooks";
import { businessProfileSchema, toBusinessProfileInput, type BusinessProfileValues } from "../schemas";

const usedOn: Record<BusinessProfile["module"], string> = {
  car: "Printed at the top of car money receipts, dealer vouchers and car report exports (PDF and Excel).",
  restaurant: "Printed at the top of restaurant money receipts and restaurant report exports (PDF and Excel).",
};

function ProfileForm({ profile, canEdit }: { profile: BusinessProfile; canEdit: boolean }) {
  const save = useSaveBusinessProfile(profile.module);
  const notify = useNotify();
  const prefix = `profile-${profile.module}`;
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting, isDirty },
    reset,
  } = useForm<BusinessProfileValues>({
    resolver: zodResolver(businessProfileSchema),
    defaultValues: { name: profile.name ?? "", address: profile.address ?? "", phone: profile.phone ?? "", email: profile.email ?? "" },
  });

  const submit = handleSubmit(async (values) => {
    try {
      const saved = await save.mutateAsync(toBusinessProfileInput(values));
      reset({ name: saved.name ?? "", address: saved.address ?? "", phone: saved.phone ?? "", email: saved.email ?? "" });
      notify(`${profile.label} profile saved.`);
    } catch (e) {
      applyApiErrors(e, setError, ["name", "address", "phone", "email"]);
    }
  });

  const field = (name: keyof BusinessProfileValues, label: string, input: React.ReactNode) => (
    <div className={name === "address" ? "flex flex-col gap-2 sm:col-span-2" : "flex flex-col gap-2"}>
      <Label htmlFor={`${prefix}-${name}`}>{label}</Label>
      {input}
      <FieldError id={`${prefix}-${name}-error`} message={errors[name]?.message} />
    </div>
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Building aria-hidden className="size-4" />
          {profile.label}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {usedOn[profile.module]}
          {profile.configured ? "" : " Not set yet: documents show the application name."}
        </p>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <FormAlert message={errors.root?.message} />
          <fieldset disabled={!canEdit} className="grid gap-4 sm:grid-cols-2">
            {field("name", "Business name", <Input id={`${prefix}-name`} aria-invalid={errors.name ? true : undefined} {...register("name")} />)}
            {field("phone", "Contact phone", <Input id={`${prefix}-phone`} placeholder="+880 1711-000000, 02-9876543" aria-invalid={errors.phone ? true : undefined} {...register("phone")} />)}
            {field("email", "Contact email", <Input id={`${prefix}-email`} type="email" aria-invalid={errors.email ? true : undefined} {...register("email")} />)}
            {field("address", "Address", <Textarea id={`${prefix}-address`} rows={2} aria-invalid={errors.address ? true : undefined} {...register("address")} />)}
          </fieldset>
          {canEdit ? (
            <div className="flex justify-end">
              <Button type="submit" disabled={isSubmitting || !isDirty}>
                {isSubmitting ? "Saving…" : "Save profile"}
              </Button>
            </div>
          ) : null}
        </form>
      </CardContent>
    </Card>
  );
}

/** Car and Restaurant business letterheads (name, address, contact) for printed documents and exports. */
export function BusinessProfiles({ canEdit }: { canEdit: boolean }) {
  const profiles = useBusinessProfiles();

  return (
    <section className="flex flex-col gap-3" aria-labelledby="business-profiles-title">
      <div>
        <h2 id="business-profiles-title" className="text-lg font-semibold">
          Business profiles
        </h2>
        <p className="text-sm text-muted-foreground">Name, address and contact shown at the top of each business&apos;s receipts and report exports.</p>
      </div>
      {profiles.isPending ? <p className="text-sm text-muted-foreground">Loading business profiles…</p> : null}
      {profiles.isError ? <p className="text-sm text-destructive">{errorMessage(profiles.error)}</p> : null}
      {profiles.data ? (
        <div className="grid gap-4 lg:grid-cols-2">
          {profiles.data.map((profile) => (
            <ProfileForm key={profile.module} profile={profile} canEdit={canEdit} />
          ))}
        </div>
      ) : null}
    </section>
  );
}
