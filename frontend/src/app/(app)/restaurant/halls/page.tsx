"use client";

import { MasterDataPage } from "@/features/restaurant-master/components/master-data-page";
import { hallResource } from "@/features/restaurant-master/config";

export default function HallsPage() {
  return <MasterDataPage resource={hallResource} />;
}
