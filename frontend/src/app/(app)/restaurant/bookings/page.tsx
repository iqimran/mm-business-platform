"use client";

import { Plus } from "lucide-react";
import Link from "next/link";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { buttonVariants } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { BookingsList } from "@/features/restaurant-bookings/components/bookings-list";

export default function HallBookingsPage() {
  const { can } = usePermissions();

  if (!can("restaurant.booking.view")) return <Forbidden />;

  return (
    <>
      <PageHeader
        title="Hall bookings"
        description="Bookings of your branches' halls. Due amounts are calculated by the server."
        actions={
          can("restaurant.booking.create") ? (
            <Link href="/restaurant/bookings/new" className={buttonVariants()}>
              <Plus aria-hidden />
              New booking
            </Link>
          ) : null
        }
      />
      <BookingsList />
    </>
  );
}
