"use client";

import { MasterDataPage } from "@/features/restaurant-master/components/master-data-page";
import { eventMenuItemResource } from "@/features/restaurant-master/config";

export default function EventMenuItemsPage() {
  return <MasterDataPage resource={eventMenuItemResource} />;
}
