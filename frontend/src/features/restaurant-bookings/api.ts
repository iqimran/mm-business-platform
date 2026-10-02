import { fetchAllPages } from "@/features/restaurant-common/lookups";
import { apiRequest } from "@/lib/api-client";
import type { Paginated } from "@/types/api";
import { type PaymentInput, type PaymentRecord, type PaymentStatus } from "@/features/restaurant-common/payments";

export type BookingStatus = "confirmed" | "completed" | "cancelled";

export const bookingStatusLabels: Record<BookingStatus, string> = {
  confirmed: "Confirmed",
  completed: "Completed",
  cancelled: "Cancelled",
};

export type HallOption = { id: string; name: string; capacity: number | null; branch_id: string; branch?: { id: string; code: string; name: string } };

export type HallBooking = {
  id: string;
  booking_no: string;
  branch?: { id: string; name: string; code: string };
  hall?: { id: string; name: string; capacity: number | null; is_active: boolean };
  customer?: { id: string; name: string; phone: string | null };
  booking_date: string;
  start_time: string;
  end_time: string;
  status: BookingStatus;
  /** Backend-computed figures (decimal strings). */
  agreed_amount: string;
  paid: string;
  due: string;
  payment_status: PaymentStatus;
  notes: string | null;
  payments?: PaymentRecord[];
  created_by?: { id: string; name: string } | null;
  cancelled_at: string | null;
  cancelled_by?: { id: string; name: string } | null;
  cancellation_reason: string | null;
};

export type BookingFilters = {
  page: number;
  search: string;
  hallId: string;
  dateFrom: string;
  dateTo: string;
  status: "" | BookingStatus;
  paymentStatus: "" | PaymentStatus;
};

export type BookingsPage = Paginated<HallBooking> & {
  summary: { count: number; agreed_amount: string; paid: string; due: string };
};

export type BookingInput = {
  hall_id: string;
  customer_id: string;
  booking_date: string;
  start_time: string;
  end_time: string;
  agreed_amount: string;
  notes: string | null;
  payment?: { amount: string; method: PaymentInput["method"]; reference: string | null } | null;
};

export type BookedSlot = { id: string; booking_no: string; start_time: string; end_time: string; status: BookingStatus };

export function fetchBookings(f: BookingFilters) {
  const params = new URLSearchParams({ page: String(f.page), per_page: "25" });
  if (f.search.trim()) params.set("search", f.search.trim());
  if (f.hallId) params.set("hall_id", f.hallId);
  if (f.dateFrom) params.set("date_from", f.dateFrom);
  if (f.dateTo) params.set("date_to", f.dateTo);
  if (f.status) params.set("status", f.status);
  if (f.paymentStatus) params.set("payment_status", f.paymentStatus);

  return apiRequest<BookingsPage>(`/restaurant/hall-bookings?${params}`);
}

export function fetchBooking(id: string) {
  return apiRequest<HallBooking>(`/restaurant/hall-bookings/${encodeURIComponent(id)}`);
}

export function createBooking(input: BookingInput) {
  return apiRequest<HallBooking>("/restaurant/hall-bookings", { method: "POST", body: input });
}

export function updateBooking(id: string, input: Omit<BookingInput, "payment">) {
  return apiRequest<HallBooking>(`/restaurant/hall-bookings/${encodeURIComponent(id)}`, { method: "PATCH", body: input });
}

export function cancelBooking(id: string, reason: string) {
  return apiRequest<HallBooking>(`/restaurant/hall-bookings/${encodeURIComponent(id)}/cancel`, { method: "POST", body: { reason } });
}

export function completeBooking(id: string) {
  return apiRequest<HallBooking>(`/restaurant/hall-bookings/${encodeURIComponent(id)}/complete`, { method: "POST" });
}

export function recordBookingPayment(id: string, input: PaymentInput) {
  return apiRequest<HallBooking>(`/restaurant/hall-bookings/${encodeURIComponent(id)}/payments`, { method: "POST", body: input });
}

export function reverseBookingPayment(id: string, paymentId: string, reason: string) {
  return apiRequest<HallBooking>(
    `/restaurant/hall-bookings/${encodeURIComponent(id)}/payments/${encodeURIComponent(paymentId)}/reverse`,
    { method: "POST", body: { reason } },
  );
}

export function fetchAvailability(hallId: string, date: string) {
  const params = new URLSearchParams({ hall_id: hallId, date });
  return apiRequest<{ hall_id: string; date: string; booked: BookedSlot[] }>(`/restaurant/hall-availability?${params}`);
}

/** Halls of the user's branches (a small catalog, all pages). */
export function fetchHalls(activeOnly: boolean) {
  return fetchAllPages<HallOption>("/restaurant/halls", activeOnly);
}
