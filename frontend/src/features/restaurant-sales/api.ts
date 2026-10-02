import { apiOpenPdf, apiRequest } from "@/lib/api-client";
import type { PaymentInput, PaymentMethod, PaymentRecord, PaymentStatus } from "@/features/restaurant-common/payments";
import type { Paginated } from "@/types/api";

export type SaleItem = {
  id: string;
  menu_item_id: string;
  item_name: string;
  unit_price: string;
  quantity: number;
  line_total: string;
};

export type FoodSale = {
  id: string;
  sale_no: string;
  branch?: { id: string; name: string; code: string };
  customer: { id: string; name: string; phone: string | null } | null;
  sold_at: string;
  notes: string | null;
  /** Backend-computed figures (decimal strings). */
  total: string;
  paid: string;
  due: string;
  payment_status: PaymentStatus;
  items_count?: number;
  items?: SaleItem[];
  payments?: PaymentRecord[];
  recorded_by?: { id: string; name: string } | null;
  is_reversed: boolean;
  reversed_at: string | null;
  reversed_by?: { id: string; name: string } | null;
  reversal_reason: string | null;
};

export type SaleFilters = {
  page: number;
  search: string;
  branchId: string;
  dateFrom: string;
  dateTo: string;
  paymentStatus: "" | PaymentStatus;
  state: "" | "active" | "reversed";
};

export type SalesPage = Paginated<FoodSale> & {
  summary: { count: number; total: string; paid: string; due: string };
};

export type NewSaleInput = {
  branch_id: string;
  customer_id: string | null;
  sold_at: string;
  notes: string | null;
  items: { menu_item_id: string; quantity: number }[];
  payment: { amount: string; method: PaymentMethod; reference: string | null } | null;
};

export function fetchSales(f: SaleFilters) {
  const params = new URLSearchParams({ page: String(f.page), per_page: "25" });
  if (f.search.trim()) params.set("search", f.search.trim());
  if (f.branchId) params.set("branch_id", f.branchId);
  if (f.dateFrom) params.set("date_from", f.dateFrom);
  if (f.dateTo) params.set("date_to", f.dateTo);
  if (f.paymentStatus) params.set("payment_status", f.paymentStatus);
  if (f.state) params.set("state", f.state);

  return apiRequest<SalesPage>(`/restaurant/sales?${params}`);
}

export function fetchSale(id: string) {
  return apiRequest<FoodSale>(`/restaurant/sales/${encodeURIComponent(id)}`);
}

export function createSale(input: NewSaleInput) {
  return apiRequest<FoodSale>("/restaurant/sales", { method: "POST", body: input });
}

export function recordPayment(saleId: string, input: PaymentInput) {
  return apiRequest<FoodSale>(`/restaurant/sales/${encodeURIComponent(saleId)}/payments`, { method: "POST", body: input });
}

export function reverseSale(saleId: string, reason: string) {
  return apiRequest<FoodSale>(`/restaurant/sales/${encodeURIComponent(saleId)}/reverse`, { method: "POST", body: { reason } });
}

export function reversePayment(saleId: string, paymentId: string, reason: string) {
  return apiRequest<FoodSale>(
    `/restaurant/sales/${encodeURIComponent(saleId)}/payments/${encodeURIComponent(paymentId)}/reverse`,
    { method: "POST", body: { reason } },
  );
}

/** Opens the money receipt (PDF) of a sale payment; reversed payments print as VOID. */
export function printSalePaymentReceipt(saleId: string, paymentId: string) {
  return apiOpenPdf(`/restaurant/sales/${encodeURIComponent(saleId)}/payments/${encodeURIComponent(paymentId)}/receipt`);
}

export type MenuOption = { id: string; label: string; hint?: string; price: string };

/** Server-side search of available menu items (a few matches, never the whole menu). */
export async function searchMenu(term: string): Promise<MenuOption[]> {
  const params = new URLSearchParams({ is_active: "1", per_page: "10" });
  if (term) params.set("search", term);
  const page = await apiRequest<Paginated<{ id: string; name: string; price: string; category?: { name: string; is_active: boolean } }>>(
    `/restaurant/menu-items?${params}`,
  );

  return page.items
    .filter((item) => item.category?.is_active !== false)
    .map((item) => ({ id: item.id, label: item.name, price: item.price, hint: `${item.category?.name ?? ""} · ${item.price}` }));
}
