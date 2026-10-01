"use client";

import { CheckCircle2, Pencil, Plus } from "lucide-react";
import { useState } from "react";
import { FormAlert } from "@/components/common/page-header";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { usePermissions } from "@/features/auth/hooks";
import { PaymentForm } from "@/features/restaurant-sales/components/payment-form";
import { PaymentsTable } from "@/features/restaurant-sales/components/payments-table";
import { ReverseButton } from "@/features/restaurant-sales/components/reverse-button";
import { SaleFigures } from "@/features/restaurant-sales/components/sale-figures";
import { today } from "@/features/restaurant-sales/schemas";
import { errorMessage } from "@/lib/form-errors";
import type { HallBooking } from "../api";
import { useCancelBooking, useCompleteBooking, useRecordBookingPayment, useReverseBookingPayment } from "../hooks";
import { BookingForm } from "./booking-form";
import { BookingPaymentBadge, BookingStatusBadge } from "./booking-status-badge";

/** Why no payment can be recorded right now (fully paid / cancelled). */
function PaymentNote({ booking }: { booking: HallBooking }) {
  if (booking.status === "cancelled") {
    return <p className="text-sm text-muted-foreground">This booking is cancelled; it cannot receive payments.</p>;
  }
  if (booking.payment_status === "paid") {
    return <p className="text-sm text-muted-foreground">Fully paid. Reverse a payment first if it needs correcting.</p>;
  }
  return null;
}

export function BookingDetail({ booking }: { booking: HallBooking }) {
  const { can } = usePermissions();
  const [editing, setEditing] = useState(false);
  const [paying, setPaying] = useState(false);
  const [actionError, setActionError] = useState<string>();
  const cancel = useCancelBooking(booking.id);
  const complete = useCompleteBooking(booking.id);
  const recordPayment = useRecordBookingPayment(booking.id);
  const reversePayment = useReverseBookingPayment(booking.id);

  const confirmed = booking.status === "confirmed";
  const hasActivePayments = (booking.payments ?? []).some((p) => !p.is_reversed);
  const canEdit = can("restaurant.booking.update") && confirmed;
  const canComplete = canEdit && booking.booking_date <= today();
  const canCancel = can("restaurant.booking.cancel") && confirmed;
  const canPay = can("restaurant.booking_payment.create") && booking.status !== "cancelled" && booking.payment_status !== "paid";

  const onComplete = () => {
    if (!window.confirm("Mark this booking as completed? Its details can no longer be changed.")) return;
    setActionError(undefined);
    complete.mutate(undefined, { onError: (e) => setActionError(errorMessage(e)) });
  };

  const details: [string, string][] = [
    ["Hall", booking.hall ? `${booking.hall.name}${booking.hall.capacity ? ` (${booking.hall.capacity} guests)` : ""}` : "—"],
    ["Branch", booking.branch ? `${booking.branch.code} — ${booking.branch.name}` : "—"],
    ["Customer", booking.customer ? `${booking.customer.name}${booking.customer.phone ? ` (${booking.customer.phone})` : ""}` : "—"],
    ["Date & time", `${booking.booking_date}, ${booking.start_time}–${booking.end_time}`],
    ["Booked by", booking.created_by?.name ?? "—"],
  ];

  return (
    <div className="flex flex-col gap-6">
      {booking.status === "cancelled" ? (
        <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">
          Cancelled{booking.cancelled_by ? ` by ${booking.cancelled_by.name}` : ""}: {booking.cancellation_reason}
        </p>
      ) : null}
      <FormAlert message={actionError} />

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <CardTitle>Booking</CardTitle>
          <div className="flex flex-wrap items-center gap-2">
            <BookingStatusBadge booking={booking} />
            <BookingPaymentBadge booking={booking} />
            {canEdit && !editing ? (
              <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                <Pencil aria-hidden />
                Edit
              </Button>
            ) : null}
            {canComplete && !editing ? (
              <Button size="sm" variant="outline" disabled={complete.isPending} onClick={onComplete}>
                <CheckCircle2 aria-hidden />
                Mark completed
              </Button>
            ) : null}
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {editing ? (
            <BookingForm booking={booking} onDone={() => setEditing(false)} />
          ) : (
            <>
              <dl className="grid gap-3 text-sm sm:grid-cols-3">
                {details.map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd>{value}</dd>
                  </div>
                ))}
              </dl>
              {booking.notes ? <p className="text-sm whitespace-pre-line text-muted-foreground">{booking.notes}</p> : null}
            </>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <div className="flex items-center gap-2">
            <CardTitle>Payments</CardTitle>
            <BookingPaymentBadge booking={booking} />
          </div>
          {canPay && !paying ? (
            <Button size="sm" onClick={() => setPaying(true)}>
              <Plus aria-hidden />
              Record payment
            </Button>
          ) : null}
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <SaleFigures total={booking.agreed_amount} paid={booking.paid} due={booking.due} labels={["Booking amount", "Total paid", "Remaining due"]} />
          <PaymentNote booking={booking} />
          {paying ? <PaymentForm due={booking.due} onSubmit={(input) => recordPayment.mutateAsync(input)} onDone={() => setPaying(false)} /> : null}
          <h3 className="text-sm font-medium">Payment history</h3>
          <PaymentsTable
            payments={booking.payments ?? []}
            canReverse={can("restaurant.booking_payment.reverse")}
            onReverse={(paymentId, reason) => reversePayment.mutateAsync({ paymentId, reason })}
          />
        </CardContent>
      </Card>

      {canCancel ? (
        <Card>
          <CardHeader>
            <CardTitle>Cancel booking</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 text-sm">
            {hasActivePayments ? (
              <p className="text-muted-foreground">Reverse (refund) this booking&apos;s payments first; a booking with payments cannot be cancelled.</p>
            ) : (
              <>
                <p className="text-muted-foreground">Cancelling frees the hall for this time. It cannot be undone.</p>
                <div>
                  <ReverseButton label="booking" onReverse={(reason) => cancel.mutateAsync(reason)} />
                </div>
              </>
            )}
          </CardContent>
        </Card>
      ) : null}
    </div>
  );
}
