"use client";

import { MasterDataPage } from "@/features/restaurant-master/components/master-data-page";
import { menuItemResource } from "@/features/restaurant-master/config";

export default function FoodMenuPage() {
  return <MasterDataPage resource={menuItemResource} />;
}
