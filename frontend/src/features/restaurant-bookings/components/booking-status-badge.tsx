import { Badge } from "@/components/ui/badge";
import { paymentStatusLabels } from "@/features/restaurant-sales/api";
import { bookingStatusLabels, type HallBooking } from "../api";

export function BookingStatusBadge({ booking }: { booking: Pick<HallBooking, "status"> }) {
  const variant = booking.status === "confirmed" ? "default" : booking.status === "completed" ? "secondary" : "outline";
  return <Badge variant={variant}>{bookingStatusLabels[booking.status]}</Badge>;
}

/** Derived from payments by the server; cancelled bookings carry no payment state. */
export function BookingPaymentBadge({ booking }: { booking: Pick<HallBooking, "status" | "payment_status"> }) {
  if (booking.status === "cancelled") return null;
  const variant = booking.payment_status === "paid" ? "secondary" : booking.payment_status === "partial" ? "outline" : "destructive";
  return <Badge variant={variant}>{paymentStatusLabels[booking.payment_status]}</Badge>;
}
