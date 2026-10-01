"use client";

import { MasterDataPage } from "@/features/car-master/components/master-data-page";
import { partyResource } from "@/features/car-master/config";

export default function PartiesPage() {
  return <MasterDataPage resource={partyResource} />;
}
