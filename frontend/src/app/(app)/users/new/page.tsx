"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { Controller, useForm } from "react-hook-form";
import { FieldError, FormAlert, Forbidden, PageHeader } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { usePermissions } from "@/features/auth/hooks";
import { CheckList } from "@/features/users/components/check-list";
import { useAssignmentOptions, useCreateUser } from "@/features/users/hooks";
import { newUserSchema, type NewUserValues } from "@/features/users/schemas";
import { applyApiErrors } from "@/lib/form-errors";

export default function NewUserPage() {
  const router = useRouter();
  const { can } = usePermissions();
  const create = useCreateUser();
  const options = useAssignmentOptions();
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<NewUserValues>({
    resolver: zodResolver(newUserSchema),
    defaultValues: { name: "", email: "", password: "", is_active: true, role_ids: [], branch_ids: [] },
  });

  if (!can("user.create")) return <Forbidden />;

  const submit = handleSubmit(async (v) => {
    try {
      const user = await create.mutateAsync(v);
      router.push(`/users/${user.id}`);
    } catch (e) {
      applyApiErrors(e, setError, ["name", "email", "password", "is_active", "role_ids", "branch_ids"]);
    }
  });

  return (
    <>
      <PageHeader title="New user" description="Create a staff account and give it roles and branches." />
      <form onSubmit={submit} noValidate className="flex flex-col gap-6">
        <FormAlert message={errors.root?.message} />
        <Card>
          <CardHeader>
            <CardTitle>Account</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label htmlFor="user-name">Name</Label>
              <Input id="user-name" aria-invalid={errors.name ? true : undefined} {...register("name")} />
              <FieldError id="user-name-error" message={errors.name?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="user-email">Email</Label>
              <Input id="user-email" type="email" autoComplete="off" aria-invalid={errors.email ? true : undefined} {...register("email")} />
              <FieldError id="user-email-error" message={errors.email?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="user-password">Initial password</Label>
              <Input id="user-password" type="password" autoComplete="new-password" aria-invalid={errors.password ? true : undefined} {...register("password")} />
              {!errors.password ? <p className="text-xs text-muted-foreground">At least 10 characters with upper- and lower-case letters and a number.</p> : null}
              <FieldError id="user-password-error" message={errors.password?.message} />
            </div>
            <Controller
              control={control}
              name="is_active"
              render={({ field }) => (
                <div className="flex items-center gap-2 self-end pb-2">
                  <Checkbox id="user-active" checked={field.value} onCheckedChange={(c) => field.onChange(c)} />
                  <Label htmlFor="user-active" className="font-normal">
                    Active (can sign in)
                  </Label>
                </div>
              )}
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Roles</CardTitle>
            <CardDescription>You can only grant roles whose permissions you hold yourself.</CardDescription>
          </CardHeader>
          <CardContent>
            {options.roles ? (
              <Controller
                control={control}
                name="role_ids"
                render={({ field }) => (
                  <CheckList
                    idPrefix="new-role"
                    options={options.roles!.map((r) => ({ id: r.id, label: r.name, hint: `${r.permissions.length} permissions` }))}
                    value={field.value}
                    onChange={field.onChange}
                  />
                )}
              />
            ) : (
              <p className="text-sm text-muted-foreground">You cannot view roles; assign them later.</p>
            )}
            <FieldError id="user-roles-error" message={errors.role_ids?.message} />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Branches</CardTitle>
            <CardDescription>The user can work only in the branches selected here.</CardDescription>
          </CardHeader>
          <CardContent>
            <Controller
              control={control}
              name="branch_ids"
              render={({ field }) => (
                <CheckList
                  idPrefix="new-branch"
                  options={options.branches.map((b) => ({ id: b.id, label: b.label, hint: b.inactive ? "Inactive" : undefined }))}
                  value={field.value}
                  onChange={field.onChange}
                />
              )}
            />
            <FieldError id="user-branches-error" message={errors.branch_ids?.message} />
          </CardContent>
        </Card>

        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => router.push("/users")}>
            Cancel
          </Button>
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? "Creating…" : "Create user"}
          </Button>
        </div>
      </form>
    </>
  );
}
