import { Badge } from "@/components/ui/badge";
import { PaymentStatusBadge } from "@/features/restaurant-common/components/payment-status-badge";
import { bookingStatusLabels, type HallBooking } from "../api";

export function BookingStatusBadge({ booking }: { booking: Pick<HallBooking, "status"> }) {
  const variant = booking.status === "confirmed" ? "default" : booking.status === "completed" ? "secondary" : "outline";
  return <Badge variant={variant}>{bookingStatusLabels[booking.status]}</Badge>;
}

/** Payment state from the server; cancelled bookings carry no payment state. */
export function BookingPaymentBadge({ booking }: { booking: Pick<HallBooking, "status" | "payment_status"> }) {
  return <PaymentStatusBadge status={booking.status === "cancelled" ? null : booking.payment_status} />;
}
