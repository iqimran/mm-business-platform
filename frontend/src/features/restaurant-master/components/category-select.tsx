"use client";

import type { ComponentProps } from "react";
import { NativeSelect } from "@/components/common/native-select";
import type { MenuCategoryRef } from "../api";
import { useMenuCategories } from "../hooks";

/**
 * Active menu categories; the record's current category is kept selectable even if it was deactivated
 * (the API allows keeping it, but not moving items into an inactive category).
 */
export function CategorySelect({ current, ...props }: { current?: MenuCategoryRef } & ComponentProps<"select">) {
  const categories = useMenuCategories(true);
  const options = categories.data ?? [];
  const showCurrent = current && !options.some((c) => c.id === current.id);

  return (
    <NativeSelect disabled={categories.isPending} {...props}>
      <option value="">{categories.isPending ? "Loading…" : categories.isError ? "Could not load categories" : "Select a category"}</option>
      {showCurrent ? <option value={current.id}>{current.name} (inactive)</option> : null}
      {options.map((c) => (
        <option key={c.id} value={c.id}>
          {c.name}
        </option>
      ))}
    </NativeSelect>
  );
}
