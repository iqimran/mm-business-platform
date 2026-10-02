import { Badge } from "@/components/ui/badge";
import { paymentStatusLabels, type PaymentStatus } from "../payments";

/** Payment state from the server; reversed/cancelled documents carry no payment state. */
export function PaymentStatusBadge({ status, voided = false, voidedLabel = "Reversed" }: { status: PaymentStatus | null; voided?: boolean; voidedLabel?: string }) {
  if (voided) return <Badge variant="outline">{voidedLabel}</Badge>;
  if (status === null) return null;

  const variant = status === "paid" ? "secondary" : status === "partial" ? "outline" : "destructive";
  return <Badge variant={variant}>{paymentStatusLabels[status]}</Badge>;
}
