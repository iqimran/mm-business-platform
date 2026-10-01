"use client";

import { Plus } from "lucide-react";
import Link from "next/link";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { buttonVariants } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { RolesTable } from "@/features/roles/components/roles-table";
import { useRoles } from "@/features/roles/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function RolesPage() {
  const { can } = usePermissions();
  const roles = useRoles();

  if (!can("role.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Roles & Permissions"
        description="Roles group permissions. Users receive permissions through their roles."
        actions={
          can("role.create") ? (
            <Link href="/roles/new" className={buttonVariants()}>
              <Plus aria-hidden />
              New role
            </Link>
          ) : null
        }
      />
      {roles.isPending ? <p className="text-sm text-muted-foreground">Loading roles…</p> : null}
      {roles.isError ? <p className="text-sm text-destructive">{errorMessage(roles.error)}</p> : null}
      {roles.data ? <RolesTable roles={roles.data} /> : null}
    </>
  );
}
