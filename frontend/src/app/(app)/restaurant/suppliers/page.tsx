"use client";

import { MasterDataPage } from "@/features/restaurant-master/components/master-data-page";
import { supplierResource } from "@/features/restaurant-master/config";

export default function RestaurantSuppliersPage() {
  return <MasterDataPage resource={supplierResource} />;
}
