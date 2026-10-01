"use client";

import { MasterDataPage } from "@/features/restaurant-master/components/master-data-page";
import { expenseCategoryResource } from "@/features/restaurant-master/config";

export default function ExpenseCategoriesPage() {
  return <MasterDataPage resource={expenseCategoryResource} />;
}
