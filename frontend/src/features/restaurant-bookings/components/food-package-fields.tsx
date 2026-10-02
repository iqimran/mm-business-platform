"use client";

import { AlertTriangle, X } from "lucide-react";
import { useRef, useState } from "react";
import { Controller, useWatch, type Control, type FieldErrors, type UseFormRegister } from "react-hook-form";
import { FieldError } from "@/components/common/page-header";
import { RecordPicker } from "@/components/common/record-picker";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { searchEventMenu } from "@/features/restaurant-common/lookups";
import { toDecimal } from "@/features/restaurant-common/money";
import { formatAmount } from "@/lib/money";
import { bookingPreview, toGuests, type BookingValues } from "../schemas";

/**
 * Optional event food package: dishes from the event menu (no item prices), priced per head for the guests.
 * Shows a preview of guests × price per head; the server calculates and stores the real total.
 */
export function FoodPackageFields({
  control,
  register,
  errors,
  hallCapacity,
  canSearchMenu,
}: {
  control: Control<BookingValues>;
  register: UseFormRegister<BookingValues>;
  errors: FieldErrors<BookingValues>;
  hallCapacity: number | null;
  canSearchMenu: boolean;
}) {
  const [hasPackage, guests, price, hallCharge] = useWatch({ control, name: ["has_package", "package_guests", "package_price", "hall_charge"] });
  const preview = bookingPreview({ has_package: hasPackage, package_guests: guests, package_price: price, hall_charge: hallCharge });
  const guestCount = toGuests(guests);
  const overCapacity = hasPackage && hallCapacity !== null && guestCount !== null && guestCount > hallCapacity;
  const [pickerKey, setPickerKey] = useState(0);
  const found = useRef(new Map<string, string>());

  return (
    <fieldset className="flex flex-col gap-4 rounded-lg border p-4">
      <legend className="px-1 text-sm font-medium">Event food package</legend>
      <Controller
        control={control}
        name="has_package"
        render={({ field }) => (
          <div className="flex items-center gap-2">
            <Checkbox id="package-enabled" checked={field.value} disabled={!canSearchMenu && !field.value} onCheckedChange={(checked) => field.onChange(checked === true)} />
            <Label htmlFor="package-enabled" className="font-normal">
              Add food for the guests (priced per head)
            </Label>
          </div>
        )}
      />
      {!canSearchMenu ? <p className="text-xs text-muted-foreground">You need permission to view event menu items to choose package items.</p> : null}

      {hasPackage ? (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-2 sm:col-span-3">
              <Label htmlFor="package-name">Package name</Label>
              <Input id="package-name" placeholder="e.g. Wedding Dinner Package" aria-invalid={errors.package_name ? true : undefined} {...register("package_name")} />
              <FieldError id="package-name-error" message={errors.package_name?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="package-guests">Guest count</Label>
              <Input id="package-guests" inputMode="numeric" placeholder="300" aria-invalid={errors.package_guests ? true : undefined} {...register("package_guests")} />
              <FieldError id="package-guests-error" message={errors.package_guests?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="package-price">Price per head</Label>
              <Input id="package-price" inputMode="decimal" placeholder="0.00" aria-invalid={errors.package_price ? true : undefined} {...register("package_price")} />
              <FieldError id="package-price-error" message={errors.package_price?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <span className="text-sm font-medium">Food package total</span>
              <span className="flex h-8 items-center text-base font-semibold tabular-nums">{formatAmount(toDecimal(preview.pkg))}</span>
            </div>
          </div>

          {overCapacity ? (
            <p role="status" className="flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
              <AlertTriangle aria-hidden className="size-4 shrink-0" />
              {guestCount} guests is more than this hall&apos;s capacity of {hallCapacity}. You can still save the booking.
            </p>
          ) : null}

          <Controller
            control={control}
            name="package_items"
            render={({ field }) => (
              <div className="flex flex-col gap-2">
                <Label htmlFor="package-add-item">Event menu items</Label>
                {canSearchMenu ? (
                  <RecordPicker
                    key={pickerKey}
                    id="package-add-item"
                    value={null}
                    search={async (term) => {
                      const options = await searchEventMenu(term);
                      options.forEach((o) => found.current.set(o.id, o.label));
                      return options.filter((o) => !field.value.some((item) => item.id === o.id));
                    }}
                    onChange={(picked) => {
                      if (picked && !field.value.some((item) => item.id === picked.id)) {
                        field.onChange([...field.value, { id: picked.id, label: found.current.get(picked.id) ?? picked.label }]);
                      }
                      setPickerKey((k) => k + 1);
                    }}
                    queryKey="restaurant-event-menu-active"
                    placeholder="Search event menu items to add…"
                    invalid={Boolean(errors.package_items)}
                    describedBy="package-items-error"
                  />
                ) : null}
                {field.value.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No items selected yet.</p>
                ) : (
                  <ul className="flex flex-wrap gap-2" aria-label="Selected food items">
                    {field.value.map((item) => (
                      <li key={item.id} className="flex items-center gap-1 rounded-md border bg-muted/40 py-0.5 pr-1 pl-2 text-sm">
                        {item.label}
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon-xs"
                          aria-label={`Remove ${item.label}`}
                          onClick={() => field.onChange(field.value.filter((i) => i.id !== item.id))}
                        >
                          <X aria-hidden />
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
                <FieldError id="package-items-error" message={errors.package_items?.message ?? errors.package_items?.root?.message} />
              </div>
            )}
          />

          <div className="flex flex-col gap-2">
            <Label htmlFor="package-notes">Package notes</Label>
            <Textarea id="package-notes" rows={2} placeholder="Serving time, special requests…" aria-invalid={errors.package_notes ? true : undefined} {...register("package_notes")} />
            <FieldError id="package-notes-error" message={errors.package_notes?.message} />
          </div>
        </>
      ) : null}
    </fieldset>
  );
}
