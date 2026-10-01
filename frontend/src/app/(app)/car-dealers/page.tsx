"use client";

import { MasterDataPage } from "@/features/car-master/components/master-data-page";
import { dealerResource } from "@/features/car-master/config";

export default function DealersPage() {
  return <MasterDataPage resource={dealerResource} />;
}
