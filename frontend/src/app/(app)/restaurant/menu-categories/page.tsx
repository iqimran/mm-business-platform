"use client";

import { MasterDataPage } from "@/features/restaurant-master/components/master-data-page";
import { menuCategoryResource } from "@/features/restaurant-master/config";

export default function MenuCategoriesPage() {
  return <MasterDataPage resource={menuCategoryResource} />;
}
