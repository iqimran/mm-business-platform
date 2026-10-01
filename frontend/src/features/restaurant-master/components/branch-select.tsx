"use client";

import type { ComponentProps } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { useSession } from "@/features/auth/hooks";
import type { BranchRef } from "../api";

/** The user's branches (UI hint only; the API checks branch access). */
export function BranchSelect({ current, ...props }: { current?: BranchRef } & ComponentProps<"select">) {
  const { data: session } = useSession();
  const branches = session?.branches ?? [];
  const showCurrent = current && !branches.some((b) => b.id === current.id);

  return (
    <NativeSelect {...props}>
      <option value="">Select a branch…</option>
      {showCurrent ? <option value={current.id}>{current.code} — {current.name}</option> : null}
      {branches.map((b) => (
        <option key={b.id} value={b.id}>
          {b.code} — {b.name}
        </option>
      ))}
    </NativeSelect>
  );
}
