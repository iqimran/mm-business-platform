"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { ArrowLeft, Trash2 } from "lucide-react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { Controller, useForm } from "react-hook-form";
import { FieldError, FormAlert, PageHeader } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { usePermissions, useSession } from "@/features/auth/hooks";
import type { AdminUser } from "@/features/users/api";
import { CheckList } from "@/features/users/components/check-list";
import { useAssignmentOptions, useDeleteUser, useSyncUserBranches, useSyncUserRoles, useUpdateUser, useUser } from "@/features/users/hooks";
import { editUserSchema, type EditUserValues } from "@/features/users/schemas";
import { applyApiErrors, errorMessage } from "@/lib/form-errors";

function AccountForm({ user, canEdit, isSelf }: { user: AdminUser; canEdit: boolean; isSelf: boolean }) {
  const update = useUpdateUser(user.id);
  const [saved, setSaved] = useState(false);
  const {
    register,
    control,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting, isDirty },
  } = useForm<EditUserValues>({
    resolver: zodResolver(editUserSchema),
    defaultValues: { name: user.name, email: user.email, password: "", is_active: user.is_active },
  });

  const submit = handleSubmit(async (v) => {
    setSaved(false);
    try {
      const updated = await update.mutateAsync({ name: v.name, email: v.email, is_active: v.is_active, ...(v.password ? { password: v.password } : {}) });
      reset({ name: updated.name, email: updated.email, password: "", is_active: updated.is_active });
      setSaved(true);
    } catch (e) {
      applyApiErrors(e, setError, ["name", "email", "password", "is_active"]);
    }
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>Account</CardTitle>
        {!canEdit ? <CardDescription>You can view this account but not change it.</CardDescription> : null}
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          <FormAlert message={errors.root?.message} />
          <fieldset disabled={!canEdit} className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-2">
              <Label htmlFor="edit-name">Name</Label>
              <Input id="edit-name" aria-invalid={errors.name ? true : undefined} {...register("name")} />
              <FieldError id="edit-name-error" message={errors.name?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="edit-email">Email</Label>
              <Input id="edit-email" type="email" aria-invalid={errors.email ? true : undefined} {...register("email")} />
              <FieldError id="edit-email-error" message={errors.email?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="edit-password">New password</Label>
              <Input id="edit-password" type="password" autoComplete="new-password" placeholder="Leave empty to keep" aria-invalid={errors.password ? true : undefined} {...register("password")} />
              {!errors.password ? <p className="text-xs text-muted-foreground">Setting a password signs the user out of other sessions.</p> : null}
              <FieldError id="edit-password-error" message={errors.password?.message} />
            </div>
            <Controller
              control={control}
              name="is_active"
              render={({ field }) => (
                <div className="flex flex-col gap-1 self-end pb-2">
                  <div className="flex items-center gap-2">
                    <Checkbox id="edit-active" checked={field.value} disabled={isSelf} onCheckedChange={(c) => field.onChange(c)} />
                    <Label htmlFor="edit-active" className="font-normal">
                      Active (can sign in)
                    </Label>
                  </div>
                  {isSelf ? <p className="text-xs text-muted-foreground">You cannot deactivate your own account.</p> : null}
                  <FieldError id="edit-active-error" message={errors.is_active?.message} />
                </div>
              )}
            />
          </fieldset>
          {canEdit ? (
            <div className="flex items-center justify-end gap-3">
              {saved && !isDirty ? <span className="text-sm text-emerald-700 dark:text-emerald-400">Saved.</span> : null}
              <Button type="submit" disabled={isSubmitting || !isDirty}>
                {isSubmitting ? "Saving…" : "Save account"}
              </Button>
            </div>
          ) : null}
        </form>
      </CardContent>
    </Card>
  );
}

function AssignmentCard({
  title,
  description,
  idPrefix,
  options,
  initial,
  canEdit,
  onSave,
}: {
  title: string;
  description: string;
  idPrefix: string;
  options: { id: string; label: string; hint?: string }[];
  initial: string[];
  canEdit: boolean;
  onSave: (ids: string[]) => Promise<unknown>;
}) {
  const [value, setValue] = useState(initial);
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const dirty = [...value].sort().join() !== [...initial].sort().join();

  const save = async () => {
    setBusy(true);
    setError(undefined);
    setSaved(false);
    try {
      await onSave(value);
      setSaved(true);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <FormAlert message={error} />
        <fieldset disabled={!canEdit}>
          <CheckList idPrefix={idPrefix} options={options} value={value} onChange={(v) => { setValue(v); setSaved(false); }} />
        </fieldset>
        {canEdit ? (
          <div className="flex items-center justify-end gap-3">
            {saved && !dirty ? <span className="text-sm text-emerald-700 dark:text-emerald-400">Saved.</span> : null}
            <Button onClick={save} disabled={busy || !dirty}>
              {busy ? "Saving…" : `Save ${title.toLowerCase()}`}
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

export default function UserPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = usePermissions();
  const { data: session } = useSession();
  const user = useUser(id);
  const options = useAssignmentOptions();
  const syncRoles = useSyncUserRoles(id);
  const syncBranches = useSyncUserBranches(id);
  const remove = useDeleteUser();
  const [error, setError] = useState<string>();

  if (user.isPending) return <p className="text-sm text-muted-foreground">Loading…</p>;
  if (user.isError) return <p className="text-sm text-destructive">{errorMessage(user.error)}</p>;

  const u = user.data;
  const isSelf = session?.user.id === u.id;
  const canEdit = can("user.update");
  const canAssign = canEdit && !isSelf;

  // Keep assignments the admin cannot see (e.g. other branches) visible in the list.
  const branchOptions = [...options.branches.map((b) => ({ id: b.id, label: b.label, hint: b.inactive ? "Inactive" : undefined }))];
  for (const b of u.branches ?? []) {
    if (!branchOptions.some((o) => o.id === b.id)) branchOptions.push({ id: b.id, label: `${b.code} — ${b.name}`, hint: undefined });
  }

  const onDelete = () => {
    if (!window.confirm(`Delete ${u.name}? This cannot be undone. Users with activity history can only be deactivated.`)) return;
    setError(undefined);
    remove.mutate(u.id, { onSuccess: () => router.push("/users"), onError: (e) => setError(errorMessage(e)) });
  };

  return (
    <>
      <Link href="/users" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" aria-hidden />
        All users
      </Link>
      <PageHeader
        title={u.name}
        description={u.email}
        actions={
          <>
            {u.is_active ? <Badge variant="secondary">Active</Badge> : <Badge variant="outline">Inactive</Badge>}
            {isSelf ? <Badge variant="outline">You</Badge> : null}
            {can("user.delete") && !isSelf ? (
              <Button variant="destructive" size="sm" onClick={onDelete} disabled={remove.isPending}>
                <Trash2 aria-hidden />
                Delete
              </Button>
            ) : null}
          </>
        }
      />
      <FormAlert message={error} />

      <AccountForm key={u.id + u.email + u.is_active} user={u} canEdit={canEdit} isSelf={isSelf} />

      {options.roles ? (
        <AssignmentCard
          key={`roles-${(u.roles ?? []).map((r) => r.id).join()}`}
          title="Roles"
          description={isSelf ? "You cannot change your own roles." : "What the user can do. You can only grant roles whose permissions you hold."}
          idPrefix="user-role"
          options={options.roles.map((r) => ({ id: r.id, label: r.name, hint: `${r.permissions.length} permissions` }))}
          initial={(u.roles ?? []).map((r) => r.id)}
          canEdit={canAssign}
          onSave={(ids) => syncRoles.mutateAsync(ids)}
        />
      ) : null}

      <AssignmentCard
        key={`branches-${(u.branches ?? []).map((b) => b.id).join()}`}
        title="Branches"
        description={isSelf ? "You cannot change your own branches." : "Where the user can work. You can only assign branches you can access."}
        idPrefix="user-branch"
        options={branchOptions}
        initial={(u.branches ?? []).map((b) => b.id)}
        canEdit={canAssign}
        onSave={(ids) => syncBranches.mutateAsync(ids)}
      />
    </>
  );
}
