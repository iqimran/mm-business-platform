"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useMemo } from "react";
import { Controller, useForm, useWatch, type Path } from "react-hook-form";
import { NativeSelect } from "@/components/common/native-select";
import { FieldError, FormAlert } from "@/components/common/page-header";
import { RecordPicker } from "@/components/common/record-picker";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { paymentMethodLabels, paymentMethods, searchCustomers } from "@/features/restaurant-sales/api";
import { SaleFigures } from "@/features/restaurant-sales/components/sale-figures";
import { toDecimal, toMinor } from "@/features/restaurant-sales/money";
import { today } from "@/features/restaurant-sales/schemas";
import { ApiError } from "@/lib/api-client";
import { errorMessage } from "@/lib/form-errors";
import type { HallBooking } from "../api";
import { useCreateBooking, useHallOptions, useUpdateBooking } from "../hooks";
import { bookingErrorFields, bookingSchema, toBookingChanges, toBookingInput, type BookingValues } from "../schemas";
import { AvailabilityPanel } from "./availability-panel";

/** New booking (with optional advance payment) or changes to a confirmed booking. */
export function BookingForm({ booking, onDone }: { booking?: HallBooking; onDone?: () => void }) {
  const router = useRouter();
  const mode = booking ? "edit" : "create";
  const halls = useHallOptions(true);
  const create = useCreateBooking();
  const update = useUpdateBooking(booking?.id ?? "");
  const schema = useMemo(() => bookingSchema(mode, booking?.booking_date), [mode, booking?.booking_date]);

  const {
    register,
    control,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<BookingValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      hall_id: booking?.hall?.id ?? "",
      customer: booking?.customer ? { id: booking.customer.id, label: booking.customer.name } : null,
      booking_date: booking?.booking_date ?? "",
      start_time: booking?.start_time ?? "",
      end_time: booking?.end_time ?? "",
      agreed_amount: booking?.agreed_amount ?? "",
      payment_amount: "",
      payment_method: "cash",
      payment_reference: "",
      notes: booking?.notes ?? "",
    },
  });

  const [hallId, date, start, end, agreedAmount, paymentAmount] = useWatch({
    control,
    name: ["hall_id", "booking_date", "start_time", "end_time", "agreed_amount", "payment_amount"],
  });
  const agreed = toMinor(agreedAmount) ?? 0;
  const paying = Math.min(toMinor(paymentAmount || "0") ?? 0, agreed);

  // Editing keeps the hall's branch; moving to another branch is not allowed.
  const hallOptions = (halls.data ?? []).filter((h) => !booking?.branch || h.branch_id === booking.branch.id);
  const currentHallMissing = booking?.hall && !hallOptions.some((h) => h.id === booking.hall?.id);

  const submit = handleSubmit(async (v) => {
    try {
      if (booking) {
        await update.mutateAsync(toBookingChanges(v));
        onDone?.();
      } else {
        const created = await create.mutateAsync(toBookingInput(v));
        router.push(`/restaurant/bookings/${created.id}`);
      }
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        for (const [field, message] of bookingErrorFields(e.errors)) setError(field as Path<BookingValues>, { message });
        return;
      }
      setError("root", { message: errorMessage(e) });
    }
  });

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-6">
      <FormAlert message={errors.root?.message} />

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="booking-hall">Hall</Label>
          <NativeSelect id="booking-hall" disabled={halls.isPending} aria-invalid={errors.hall_id ? true : undefined} {...register("hall_id")}>
            <option value="">{halls.isPending ? "Loading halls…" : halls.isError ? "Could not load halls" : "Select a hall…"}</option>
            {currentHallMissing && booking?.hall ? <option value={booking.hall.id}>{booking.hall.name} (inactive)</option> : null}
            {hallOptions.map((h) => (
              <option key={h.id} value={h.id}>
                {h.name}
                {h.branch ? ` — ${h.branch.code}` : ""}
                {h.capacity ? ` (${h.capacity} guests)` : ""}
              </option>
            ))}
          </NativeSelect>
          {halls.data && halls.data.length === 0 ? <p className="text-xs text-muted-foreground">No active halls yet. Add one under Restaurant → Halls.</p> : null}
          <FieldError id="booking-hall-error" message={errors.hall_id?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="booking-customer">Customer</Label>
          <Controller
            control={control}
            name="customer"
            render={({ field }) => (
              <RecordPicker
                id="booking-customer"
                value={field.value}
                onChange={field.onChange}
                search={searchCustomers}
                queryKey="restaurant-customers-active"
                placeholder="Search customers…"
                invalid={Boolean(errors.customer)}
                describedBy="booking-customer-error"
              />
            )}
          />
          <FieldError id="booking-customer-error" message={errors.customer?.message} />
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="booking-date">Booking date</Label>
          <Input id="booking-date" type="date" min={booking ? undefined : today()} aria-invalid={errors.booking_date ? true : undefined} {...register("booking_date")} />
          <FieldError id="booking-date-error" message={errors.booking_date?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="booking-start">Start time</Label>
          <Input id="booking-start" type="time" aria-invalid={errors.start_time ? true : undefined} {...register("start_time")} />
          <FieldError id="booking-start-error" message={errors.start_time?.message} />
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor="booking-end">End time</Label>
          <Input id="booking-end" type="time" aria-invalid={errors.end_time ? true : undefined} {...register("end_time")} />
          <FieldError id="booking-end-error" message={errors.end_time?.message} />
        </div>
      </div>

      <AvailabilityPanel hallId={hallId} date={date} start={start} end={end} ignoreBookingId={booking?.id} />

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="flex flex-col gap-2">
          <Label htmlFor="booking-amount">Agreed amount</Label>
          <Input id="booking-amount" inputMode="decimal" placeholder="0.00" aria-invalid={errors.agreed_amount ? true : undefined} {...register("agreed_amount")} />
          {booking ? <p className="text-xs text-muted-foreground">Cannot be less than the amount already paid ({booking.paid}).</p> : null}
          <FieldError id="booking-amount-error" message={errors.agreed_amount?.message} />
        </div>
      </div>

      {booking ? null : (
        <fieldset className="flex flex-col gap-4 rounded-lg border p-4">
          <legend className="px-1 text-sm font-medium">Advance payment (optional)</legend>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="flex flex-col gap-2">
              <Label htmlFor="booking-pay-amount">Amount</Label>
              <Input id="booking-pay-amount" inputMode="decimal" placeholder="0.00 (unpaid)" aria-invalid={errors.payment_amount ? true : undefined} {...register("payment_amount")} />
              <button
                type="button"
                className="w-fit text-xs text-muted-foreground underline-offset-2 hover:underline disabled:opacity-50"
                disabled={agreed === 0}
                onClick={() => setValue("payment_amount", toDecimal(agreed), { shouldValidate: true })}
              >
                Full payment ({toDecimal(agreed)})
              </button>
              <FieldError id="booking-pay-amount-error" message={errors.payment_amount?.message} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="booking-pay-method">Method</Label>
              <NativeSelect id="booking-pay-method" {...register("payment_method")}>
                {paymentMethods.map((m) => (
                  <option key={m} value={m}>
                    {paymentMethodLabels[m]}
                  </option>
                ))}
              </NativeSelect>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="booking-pay-reference">Reference</Label>
              <Input id="booking-pay-reference" placeholder="Transaction no." aria-invalid={errors.payment_reference ? true : undefined} {...register("payment_reference")} />
              <FieldError id="booking-pay-reference-error" message={errors.payment_reference?.message} />
            </div>
          </div>
          <SaleFigures total={toDecimal(agreed)} paid={toDecimal(paying)} due={toDecimal(agreed - paying)} labels={["Agreed", "Paying now", "Due after booking"]} />
          <p className="text-xs text-muted-foreground">Preview only. The server calculates the final figures.</p>
        </fieldset>
      )}

      <div className="flex flex-col gap-2">
        <Label htmlFor="booking-notes">Notes</Label>
        <Textarea id="booking-notes" rows={2} placeholder="Event type, menu, decoration…" aria-invalid={errors.notes ? true : undefined} {...register("notes")} />
        <FieldError id="booking-notes-error" message={errors.notes?.message} />
      </div>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => (onDone ? onDone() : router.push("/restaurant/bookings"))}>
          Cancel
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? "Saving…" : booking ? "Save changes" : "Book hall"}
        </Button>
      </div>
    </form>
  );
}
