import { Badge } from "@/components/ui/badge";
import { carStatusLabels, type CarStatus } from "../api";

const variants: Record<CarStatus, "default" | "secondary" | "outline"> = {
  PURCHASED: "outline",
  IN_STOCK: "secondary",
  PREPARATION: "secondary",
  READY_FOR_SALE: "default",
  SOLD: "outline",
  COMPLETED: "outline",
};

export function CarStatusBadge({ status }: { status: CarStatus }) {
  return <Badge variant={variants[status]}>{carStatusLabels[status]}</Badge>;
}
