"use client";

import { useRouter } from "next/navigation";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { RoleForm } from "@/features/roles/components/role-form";
import { useCreateRole, usePermissionCatalog } from "@/features/roles/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function NewRolePage() {
  const router = useRouter();
  const { can } = usePermissions();
  const catalog = usePermissionCatalog();
  const createRole = useCreateRole();

  if (!can("role.create")) return <Forbidden />;

  return (
    <>
      <PageHeader title="New role" description="Choose a name and the permissions this role grants." />
      {catalog.isPending ? <p className="text-sm text-muted-foreground">Loading permissions…</p> : null}
      {catalog.isError ? <p className="text-sm text-destructive">{errorMessage(catalog.error)}</p> : null}
      {catalog.data ? (
        <RoleForm
          catalog={catalog.data}
          submitLabel="Create role"
          onCancel={() => router.push("/roles")}
          onSubmit={async (input) => {
            await createRole.mutateAsync(input);
            router.push("/roles");
          }}
        />
      ) : null}
    </>
  );
}
