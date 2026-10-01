"use client";

import { Trash2 } from "lucide-react";
import { useRef, useState } from "react";
import type { FieldErrors, UseFieldArrayReturn, UseFormRegister } from "react-hook-form";
import { FieldError } from "@/components/common/page-header";
import { RecordPicker } from "@/components/common/record-picker";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { formatAmount } from "@/lib/money";
import { searchMenu, type MenuOption } from "../api";
import { lineTotalMinor, toDecimal, toQuantity } from "../money";
import type { SaleValues } from "../schemas";

/**
 * Sale lines: pick menu items (server search), edit quantities, see line totals.
 * Unit prices shown here are the current menu prices; the API applies the price at the moment of sale.
 */
export function SaleLines({
  lines,
  values,
  register,
  errors,
}: {
  lines: UseFieldArrayReturn<SaleValues, "items">;
  values: SaleValues["items"];
  register: UseFormRegister<SaleValues>;
  errors: FieldErrors<SaleValues>;
}) {
  const found = useRef(new Map<string, MenuOption>());
  const [pickerKey, setPickerKey] = useState(0);

  const search = async (term: string) => {
    const options = await searchMenu(term);
    options.forEach((o) => found.current.set(o.id, o));
    return options;
  };

  const add = (picked: { id: string } | null) => {
    const option = picked ? found.current.get(picked.id) : undefined;
    if (!option) return;

    const index = values.findIndex((line) => line.menu_item_id === option.id);
    if (index >= 0) {
      // Same dish again: increase the quantity instead of adding a duplicate line.
      lines.update(index, { ...values[index], quantity: String((toQuantity(values[index].quantity) ?? 0) + 1) });
    } else {
      lines.append({ menu_item_id: option.id, name: option.label, unit_price: option.price, quantity: "1" });
    }
    setPickerKey((k) => k + 1);
  };

  const listError = errors.items?.message ?? errors.items?.root?.message;

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2">
        <Label htmlFor="sale-add-item">Add menu item</Label>
        <RecordPicker
          key={pickerKey}
          id="sale-add-item"
          value={null}
          onChange={add}
          search={search}
          queryKey="restaurant-menu-available"
          placeholder="Type a dish name…"
          invalid={Boolean(listError)}
        />
        <FieldError id="sale-items-error" message={listError} />
      </div>

      <div className="rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead className="text-right">Unit price</TableHead>
              <TableHead className="w-28">Quantity</TableHead>
              <TableHead className="text-right">Line total</TableHead>
              <TableHead className="w-12">
                <span className="sr-only">Remove</span>
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {lines.fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="py-6 text-center text-muted-foreground">
                  No items yet. Search the menu above to add dishes.
                </TableCell>
              </TableRow>
            ) : null}
            {lines.fields.map((field, index) => {
              const line = values[index];
              const total = line ? lineTotalMinor(line.quantity, line.unit_price) : null;
              const error = errors.items?.[index]?.quantity?.message;
              return (
                <TableRow key={field.id}>
                  <TableCell className="font-medium whitespace-normal">{field.name}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatAmount(field.unit_price)}</TableCell>
                  <TableCell className="whitespace-normal">
                    <Input
                      aria-label={`Quantity of ${field.name}`}
                      inputMode="numeric"
                      className="w-24"
                      aria-invalid={error ? true : undefined}
                      {...register(`items.${index}.quantity`)}
                    />
                    <FieldError id={`sale-line-${index}-error`} message={error} />
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{total === null ? "—" : formatAmount(toDecimal(total))}</TableCell>
                  <TableCell>
                    <Button type="button" variant="ghost" size="icon-sm" aria-label={`Remove ${field.name}`} onClick={() => lines.remove(index)}>
                      <Trash2 aria-hidden />
                    </Button>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
