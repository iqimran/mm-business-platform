"use client";

import { useParams, useRouter } from "next/navigation";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { usePermissions } from "@/features/auth/hooks";
import { RoleForm } from "@/features/roles/components/role-form";
import { usePermissionCatalog, useRole, useUpdateRole } from "@/features/roles/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function EditRolePage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = usePermissions();
  const role = useRole(id);
  const catalog = usePermissionCatalog();
  const updateRole = useUpdateRole(id);

  if (!can("role.view")) return <Forbidden />;

  const loading = role.isPending || catalog.isPending;
  const failure = role.error ?? catalog.error;

  return (
    <>
      <PageHeader
        title={role.data ? role.data.name : "Role"}
        description={role.data ? `Assigned to ${role.data.users_count} user(s).` : undefined}
        actions={role.data?.is_system ? <Badge variant="secondary">System role</Badge> : null}
      />
      {loading ? <p className="text-sm text-muted-foreground">Loading…</p> : null}
      {failure ? <p className="text-sm text-destructive">{errorMessage(failure)}</p> : null}

      {role.data && catalog.data ? (
        can("role.update") ? (
          <RoleForm
            role={role.data}
            catalog={catalog.data}
            submitLabel="Save changes"
            onCancel={() => router.push("/roles")}
            onSubmit={async (input) => {
              await updateRole.mutateAsync(input);
              router.push("/roles");
            }}
          />
        ) : (
          <ul className="flex flex-wrap gap-1.5 text-xs">
            {role.data.permissions.map((permission) => (
              <li key={permission} className="rounded-md bg-muted px-2 py-1 font-mono">
                {permission}
              </li>
            ))}
          </ul>
        )
      ) : null}
    </>
  );
}
