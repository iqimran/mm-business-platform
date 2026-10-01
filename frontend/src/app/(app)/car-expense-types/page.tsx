"use client";

import { MasterDataPage } from "@/features/car-master/components/master-data-page";
import { expenseTypeResource } from "@/features/car-master/config";

export default function ExpenseTypesPage() {
  return <MasterDataPage resource={expenseTypeResource} />;
}
