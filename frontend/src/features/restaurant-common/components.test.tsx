import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { Forbidden } from "@/components/common/page-header";
import { AmountSummary } from "./components/amount-summary";
import { PaymentStatusBadge } from "./components/payment-status-badge";
import { PaymentsTable } from "./components/payments-table";
import type { PaymentRecord } from "./payments";

const html = (node: React.ReactElement) => renderToStaticMarkup(node);

describe("financial display (values come from the server)", () => {
  it("shows server amounts formatted, without recalculating", () => {
    const out = html(<AmountSummary total="1178.50" paid="250.00" due="928.50" />);
    expect(out).toContain("1,178.50");
    expect(out).toContain("250.00");
    expect(out).toContain("928.50");
    expect(out).toMatch(/Total[\s\S]*Paid[\s\S]*Due/);
  });

  it("supports booking labels", () => {
    const out = html(<AmountSummary total="30000.00" paid="10000.00" due="20000.00" labels={["Booking amount", "Total paid", "Remaining due"]} />);
    expect(out).toContain("Booking amount");
    expect(out).toContain("Remaining due");
    expect(out).toContain("20,000.00");
  });
});

describe("payment status badge", () => {
  it("labels the server-derived state", () => {
    expect(html(<PaymentStatusBadge status="unpaid" />)).toContain("Unpaid");
    expect(html(<PaymentStatusBadge status="partial" />)).toContain("Partially paid");
    expect(html(<PaymentStatusBadge status="paid" />)).toContain("Paid");
  });

  it("shows no payment state for reversed or cancelled documents", () => {
    expect(html(<PaymentStatusBadge status="paid" voided />)).toContain("Reversed");
    expect(html(<PaymentStatusBadge status={null} />)).toBe("");
  });
});

describe("payments history", () => {
  const payment = (overrides: Partial<PaymentRecord>): PaymentRecord => ({
    id: "p1", payment_date: "2026-10-02", amount: "5000.00", method: "cash", reference: "R-1", notes: null,
    recorded_by: { id: "u1", name: "Rahim" }, is_reversed: false, reversed_at: null, reversal_reason: null, ...overrides,
  });

  it("shows an empty state", () => {
    expect(html(<PaymentsTable payments={[]} canReverse onReverse={async () => {}} />)).toContain("No payments recorded.");
  });

  it("keeps reversed payments visible and only offers reversal with permission", () => {
    const rows = [payment({}), payment({ id: "p2", is_reversed: true, reversal_reason: "Cheque bounced" })];
    const withPermission = html(<PaymentsTable payments={rows} canReverse onReverse={async () => {}} />);
    expect(withPermission).toContain("Reversed: Cheque bounced");
    expect(withPermission).toContain("Rahim");
    expect(withPermission.match(/>Reverse</g)).toHaveLength(1);

    const withoutPermission = html(<PaymentsTable payments={rows} canReverse={false} onReverse={async () => {}} />);
    expect(withoutPermission).not.toContain(">Reverse<");
  });
});

describe("permission denied", () => {
  it("shows the default or a specific message", () => {
    expect(html(<Forbidden />)).toContain("You do not have permission to view this page.");
    expect(html(<Forbidden message="Missing: restaurant.menu.view." />)).toContain("Missing: restaurant.menu.view.");
  });
});
