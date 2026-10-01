"use client";

import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";

/** Accessible multi-select as a list of checkboxes. */
export function CheckList({
  idPrefix,
  options,
  value,
  onChange,
}: {
  idPrefix: string;
  options: { id: string; label: string; hint?: string }[];
  value: string[];
  onChange: (next: string[]) => void;
}) {
  if (options.length === 0) return <p className="text-sm text-muted-foreground">Nothing to choose from.</p>;

  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {options.map((o) => {
        const id = `${idPrefix}-${o.id}`;
        const checked = value.includes(o.id);
        return (
          <div key={o.id} className="flex items-start gap-2">
            <Checkbox
              id={id}
              checked={checked}
              className="mt-0.5"
              onCheckedChange={(c) => onChange(c ? [...value, o.id] : value.filter((v) => v !== o.id))}
            />
            <Label htmlFor={id} className="flex flex-col items-start gap-0.5 font-normal">
              {o.label}
              {o.hint ? <span className="text-xs text-muted-foreground">{o.hint}</span> : null}
            </Label>
          </div>
        );
      })}
    </div>
  );
}
