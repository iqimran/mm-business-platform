"use client";

import { useQuery } from "@tanstack/react-query";
import { X } from "lucide-react";
import { useId, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useDebouncedValue } from "@/hooks/use-debounced-value";

export type PickedRecord = { id: string; label: string };

/**
 * Searchable selector for large lists (customers, dealers, ...): the server is queried with the
 * typed text and returns at most a few matches, so no list is ever truncated or fully loaded.
 */
export function RecordPicker({
  id,
  value,
  onChange,
  search,
  queryKey,
  placeholder = "Type to search…",
  invalid,
  describedBy,
}: {
  id: string;
  value: PickedRecord | null;
  onChange: (record: PickedRecord | null) => void;
  /** Returns up to ~10 matches for the term. */
  search: (term: string) => Promise<{ id: string; label: string; hint?: string }[]>;
  queryKey: string;
  placeholder?: string;
  invalid?: boolean;
  describedBy?: string;
}) {
  const listId = useId();
  const [text, setText] = useState("");
  const [open, setOpen] = useState(false);
  const term = useDebouncedValue(text.trim(), 250);

  const results = useQuery({
    queryKey: ["record-picker", queryKey, term],
    queryFn: () => search(term),
    enabled: open,
    staleTime: 30_000,
  });

  if (value) {
    return (
      <div className="flex h-8 items-center justify-between gap-1 rounded-lg border bg-muted/40 pl-2.5 text-sm" id={id}>
        <span className="truncate">{value.label}</span>
        <Button type="button" variant="ghost" size="icon-xs" aria-label="Clear selection" onClick={() => onChange(null)}>
          <X aria-hidden />
        </Button>
      </div>
    );
  }

  const items = results.data ?? [];

  return (
    <div className="relative">
      <Input
        id={id}
        type="search"
        role="combobox"
        autoComplete="off"
        aria-expanded={open && items.length > 0}
        aria-controls={listId}
        aria-invalid={invalid ? true : undefined}
        aria-describedby={describedBy}
        placeholder={placeholder}
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
      />
      {open ? (
        <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border bg-background shadow-md">
          {results.isFetching && items.length === 0 ? <div className="px-3 py-2 text-sm text-muted-foreground">Searching…</div> : null}
          {!results.isFetching && items.length === 0 ? (
            <div className="px-3 py-2 text-sm text-muted-foreground">{term ? "No matches." : "Start typing to search."}</div>
          ) : null}
          {items.length > 0 ? (
            <ul id={listId} role="listbox">
              {items.map((item) => (
                <li key={item.id} role="option" aria-selected={false}>
                  <button
                    type="button"
                    className="w-full px-3 py-2 text-left text-sm hover:bg-muted"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => {
                      onChange({ id: item.id, label: item.label });
                      setText("");
                      setOpen(false);
                    }}
                  >
                    <div className="font-medium">{item.label}</div>
                    {item.hint ? <div className="text-xs text-muted-foreground">{item.hint}</div> : null}
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
