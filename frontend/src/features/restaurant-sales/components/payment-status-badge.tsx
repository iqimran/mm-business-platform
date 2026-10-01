import { Badge } from "@/components/ui/badge";
import { paymentStatusLabels, type FoodSale } from "../api";

export function PaymentStatusBadge({ sale }: { sale: Pick<FoodSale, "payment_status" | "is_reversed"> }) {
  if (sale.is_reversed) return <Badge variant="outline">Reversed</Badge>;

  const variant = sale.payment_status === "paid" ? "secondary" : sale.payment_status === "partial" ? "outline" : "destructive";
  return <Badge variant={variant}>{paymentStatusLabels[sale.payment_status]}</Badge>;
}
