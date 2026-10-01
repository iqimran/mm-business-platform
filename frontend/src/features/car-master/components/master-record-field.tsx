"use client";

import { useState } from "react";
import { Controller, type Control, type FieldValues, type Path } from "react-hook-form";
import { RecordPicker, type PickedRecord } from "@/components/common/record-picker";
import { searchActive } from "../api";
import type { MasterResource } from "../config";

/**
 * Form field (react-hook-form) holding the id of a dealer/party picked by server-side search.
 * `initial` labels an already-selected record (e.g. when editing).
 */
export function MasterRecordField<T extends FieldValues>({
  control,
  name,
  resource,
  id,
  initial,
  placeholder,
  invalid,
  describedBy,
}: {
  control: Control<T>;
  name: Path<T>;
  resource: MasterResource;
  id: string;
  initial?: PickedRecord | null;
  placeholder?: string;
  invalid?: boolean;
  describedBy?: string;
}) {
  const [picked, setPicked] = useState<PickedRecord | null>(initial ?? null);

  return (
    <Controller
      control={control}
      name={name}
      render={({ field }) => (
        <RecordPicker
          id={id}
          queryKey={resource.path}
          search={(term) => searchActive(resource, term)}
          value={field.value && picked?.id === field.value ? picked : null}
          onChange={(record) => {
            setPicked(record);
            field.onChange(record?.id ?? "");
          }}
          placeholder={placeholder}
          invalid={invalid}
          describedBy={describedBy}
        />
      )}
    />
  );
}
