"use client";

import { Pencil, Trash2 } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { FormAlert } from "@/components/common/page-header";
import { Badge } from "@/components/ui/badge";
import { Button, buttonVariants } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { errorMessage } from "@/lib/form-errors";
import type { Role } from "../api";
import { useDeleteRole } from "../hooks";

export function RolesTable({ roles }: { roles: Role[] }) {
  const { can } = usePermissions();
  const deleteRole = useDeleteRole();
  const [error, setError] = useState<string>();

  const remove = (role: Role) => {
    if (!window.confirm(`Delete the role "${role.name}"? This cannot be undone.`)) return;
    setError(undefined);
    deleteRole.mutate(role.id, { onError: (e) => setError(errorMessage(e)) });
  };

  return (
    <div className="flex flex-col gap-3">
      <FormAlert message={error} />
      <div className="rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Role</TableHead>
              <TableHead className="text-right">Permissions</TableHead>
              <TableHead className="text-right">Users</TableHead>
              <TableHead className="w-28 text-right">
                <span className="sr-only">Actions</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {roles.map((role) => (
              <TableRow key={role.id}>
                <TableCell>
                  <div className="flex items-center gap-2 font-medium">
                    {role.name}
                    {role.is_system ? <Badge variant="secondary">System</Badge> : null}
                  </div>
                  {role.description ? <div className="text-xs text-muted-foreground">{role.description}</div> : null}
                </TableCell>
                <TableCell className="text-right tabular-nums">{role.permissions.length}</TableCell>
                <TableCell className="text-right tabular-nums">{role.users_count}</TableCell>
                <TableCell>
                  <div className="flex justify-end gap-1">
                    <Link
                      href={`/roles/${role.id}`}
                      aria-label={`${can("role.update") ? "Edit" : "View"} ${role.name}`}
                      className={buttonVariants({ variant: "ghost", size: "icon-sm" })}
                    >
                      <Pencil aria-hidden />
                    </Link>
                    {can("role.delete") && !role.is_system ? (
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={`Delete ${role.name}`}
                        disabled={deleteRole.isPending}
                        onClick={() => remove(role)}
                      >
                        <Trash2 aria-hidden />
                      </Button>
                    ) : null}
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
