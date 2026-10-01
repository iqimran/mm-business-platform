"use client";

import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { buttonVariants } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { BookingDetail } from "@/features/restaurant-bookings/components/booking-detail";
import { useBooking } from "@/features/restaurant-bookings/hooks";
import { errorMessage } from "@/lib/form-errors";

export default function HallBookingPage() {
  const { id } = useParams<{ id: string }>();
  const { can } = usePermissions();
  const booking = useBooking(id);

  if (!can("restaurant.booking.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title={booking.data ? `Booking ${booking.data.booking_no}` : "Hall booking"}
        actions={
          <Link href="/restaurant/bookings" className={buttonVariants({ variant: "outline" })}>
            <ArrowLeft aria-hidden />
            All bookings
          </Link>
        }
      />
      {booking.isPending ? <p className="text-sm text-muted-foreground">Loading booking…</p> : null}
      {booking.isError ? <p className="text-sm text-destructive">{errorMessage(booking.error)}</p> : null}
      {booking.data ? <BookingDetail booking={booking.data} /> : null}
    </>
  );
}
