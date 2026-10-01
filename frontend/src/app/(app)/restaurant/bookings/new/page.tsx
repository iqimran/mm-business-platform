"use client";

import { Forbidden, PageHeader } from "@/components/common/page-header";
import { usePermissions } from "@/features/auth/hooks";
import { BookingForm } from "@/features/restaurant-bookings/components/booking-form";

export default function NewHallBookingPage() {
  const { can } = usePermissions();

  // Hall options come from the halls list, so viewing halls is needed to pick one.
  if (!can("restaurant.booking.create") || !can("restaurant.hall.view")) return <Forbidden />;

  return (
    <>
      <PageHeader title="New hall booking" description="Choose a hall, date and time; existing bookings are shown before you save." />
      <BookingForm />
    </>
  );
}
