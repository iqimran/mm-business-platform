"use client";

import { Pencil, Plus } from "lucide-react";
import { useState } from "react";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { usePermissions } from "@/features/auth/hooks";
import { BusinessProfiles } from "@/features/settings/components/business-profiles";
import { SettingForm } from "@/features/settings/components/setting-form";
import { useSettings } from "@/features/settings/hooks";
import { isBusinessProfileKey } from "@/features/settings/api";
import { formatValue } from "@/features/settings/schemas";
import { errorMessage } from "@/lib/form-errors";

/** null = no editor open, "" = adding a new setting, otherwise the key being edited. */
type Editing = string | null;

export default function SettingsPage() {
  const { can } = usePermissions();
  const settings = useSettings();
  const [editing, setEditing] = useState<Editing>(null);
  const canEdit = can("setting.update");
  const generic = settings.data?.filter((setting) => !isBusinessProfileKey(setting.key));

  if (!can("setting.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Settings"
        description="Application-wide configuration. Every change is recorded in the audit log."
        actions={
          canEdit && editing === null ? (
            <Button onClick={() => setEditing("")}>
              <Plus aria-hidden />
              Add setting
            </Button>
          ) : null
        }
      />

      <BusinessProfiles canEdit={canEdit} />

      <h2 className="text-lg font-semibold">Other settings</h2>
      {editing === "" ? <SettingForm onDone={() => setEditing(null)} /> : null}

      {settings.isPending ? <p className="text-sm text-muted-foreground">Loading settings…</p> : null}
      {settings.isError ? <p className="text-sm text-destructive">{errorMessage(settings.error)}</p> : null}

      {generic ? (
        generic.length === 0 ? (
          <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
            No settings yet.{canEdit ? " Use “Add setting” to create the first one." : ""}
          </div>
        ) : (
          <div className="rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Key</TableHead>
                  <TableHead>Value</TableHead>
                  <TableHead className="hidden md:table-cell">Description</TableHead>
                  <TableHead className="w-16 text-right">
                    <span className="sr-only">Actions</span>
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {generic.map((setting) =>
                  editing === setting.key ? (
                    <TableRow key={setting.key}>
                      <TableCell colSpan={4} className="whitespace-normal">
                        <SettingForm setting={setting} onDone={() => setEditing(null)} />
                      </TableCell>
                    </TableRow>
                  ) : (
                    <TableRow key={setting.key}>
                      <TableCell className="font-mono text-xs">{setting.key}</TableCell>
                      <TableCell className="max-w-xs truncate">{formatValue(setting.value)}</TableCell>
                      <TableCell className="hidden text-muted-foreground md:table-cell">{setting.description}</TableCell>
                      <TableCell className="text-right">
                        {canEdit ? (
                          <Button
                            variant="ghost"
                            size="icon-sm"
                            aria-label={`Edit ${setting.key}`}
                            disabled={editing !== null}
                            onClick={() => setEditing(setting.key)}
                          >
                            <Pencil aria-hidden />
                          </Button>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  ),
                )}
              </TableBody>
            </Table>
          </div>
        )
      ) : null}
    </>
  );
}
