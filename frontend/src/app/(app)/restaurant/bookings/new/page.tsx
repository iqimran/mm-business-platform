"use client";

import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { missingMessage, missingPermissions } from "@/features/restaurant-common/permissions";
import { BookingForm } from "@/features/restaurant-bookings/components/booking-form";

export default function NewHallBookingPage() {
  const { can } = usePermissions();

  if (!can("restaurant.booking.create")) return <Forbidden />;
  // Halls and customers are picked from their lists, so viewing them is needed too.
  const missing = missingPermissions(can, "newBooking");
  if (missing.length > 0) return <Forbidden message={missingMessage(missing)} />;

  return (
    <>
      <PageHeader title="New hall booking" description="Choose a hall, date and time; existing bookings are shown before you save." />
      <BookingForm />
    </>
  );
}
