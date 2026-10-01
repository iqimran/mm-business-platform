"use client";

import { X } from "lucide-react";
import { useEffect, useId, useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { useCars } from "../hooks";

export type PickedCar = { id: string; label: string };

/**
 * Searchable car selector (brand, model, chassis or registration). Results come from the
 * branch-scoped cars API, so only accessible cars can be picked.
 */
export function CarPicker({ value, onChange, placeholder = "Search a car…" }: { value: PickedCar | null; onChange: (car: PickedCar | null) => void; placeholder?: string }) {
  const listId = useId();
  const [text, setText] = useState("");
  const [search, setSearch] = useState("");
  const [open, setOpen] = useState(false);

  // Debounce typing so each keystroke does not hit the API.
  useEffect(() => {
    const t = setTimeout(() => setSearch(text.trim()), 250);
    return () => clearTimeout(t);
  }, [text]);

  const cars = useCars({ page: 1, search, status: "", branchId: "" });
  const results = search.length >= 2 ? (cars.data?.items ?? []).slice(0, 8) : [];

  if (value) {
    return (
      <div className="flex h-8 items-center gap-1 rounded-lg border bg-muted/40 pl-2.5 text-sm">
        <span className="max-w-64 truncate">{value.label}</span>
        <Button variant="ghost" size="icon-xs" aria-label="Clear car" onClick={() => onChange(null)}>
          <X aria-hidden />
        </Button>
      </div>
    );
  }

  return (
    <div className="relative w-64">
      <Input
        type="search"
        role="combobox"
        aria-expanded={open && results.length > 0}
        aria-controls={listId}
        aria-label="Car"
        placeholder={placeholder}
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
      />
      {open && results.length > 0 ? (
        <ul id={listId} role="listbox" className="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border bg-background shadow-md">
          {results.map((car) => {
            const label = `${car.brand} ${car.model} · ${car.registration_number ?? car.chassis_number}`;
            return (
              <li key={car.id} role="option" aria-selected={false}>
                <button
                  type="button"
                  className="w-full px-3 py-2 text-left text-sm hover:bg-muted"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => {
                    onChange({ id: car.id, label });
                    setText("");
                    setOpen(false);
                  }}
                >
                  <div className="font-medium">
                    {car.brand} {car.model} {car.model_year ?? ""}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {car.registration_number ?? "—"} · {car.chassis_number} · {car.branch?.code}
                  </div>
                </button>
              </li>
            );
          })}
        </ul>
      ) : null}
      {open && search.length >= 2 && cars.data && results.length === 0 ? (
        <div className="absolute z-20 mt-1 w-full rounded-lg border bg-background px-3 py-2 text-sm text-muted-foreground shadow-md">No cars found.</div>
      ) : null}
    </div>
  );
}
