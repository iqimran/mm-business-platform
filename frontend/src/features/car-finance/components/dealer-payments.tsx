"use client";

import { Plus } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { usePermissions } from "@/features/auth/hooks";
import { errorMessage } from "@/lib/form-errors";
import { useDealerPayments, useRecordDealerPayment, useReverseDealerPayment } from "../hooks";
import { PaymentForm, PaymentsTable, Position } from "./payments";

/**
 * Dealer payable = purchase amount − payments made. Independent of the sale and its payments.
 */
export function DealerPayments({ carId }: { carId: string }) {
  const { can } = usePermissions();
  const canView = can("car.dealer_payment.view");
  const payments = useDealerPayments(carId, canView);
  const record = useRecordDealerPayment(carId);
  const reverse = useReverseDealerPayment(carId);
  const [paying, setPaying] = useState(false);

  if (!canView) return null;

  const dealer = payments.data?.dealer;

  return (
    <div className="flex flex-col gap-3 border-t pt-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-medium">Payments to dealer</h3>
        {dealer && can("car.dealer_payment.create") && !dealer.is_settled && !paying ? (
          <Button variant="outline" size="sm" onClick={() => setPaying(true)}>
            <Plus aria-hidden />
            Pay dealer
          </Button>
        ) : null}
      </div>
      {payments.isError ? <p className="text-sm text-destructive">{errorMessage(payments.error)}</p> : null}
      {dealer ? (
        <Position
          items={[
            ["Purchase amount", dealer.purchase_amount],
            ["Paid", dealer.paid],
            ["Dealer payable", dealer.payable, true],
          ]}
        />
      ) : null}
      {paying && dealer ? (
        <PaymentForm
          idPrefix="dealer-payment"
          outstanding={dealer.payable}
          outstandingLabel="Dealer payable"
          onSubmit={(input) => record.mutateAsync(input)}
          onCancel={() => setPaying(false)}
        />
      ) : null}
      {payments.data ? (
        <PaymentsTable
          payments={payments.data.items}
          canReverse={can("car.dealer_payment.reverse")}
          onReverse={(id, reason) => reverse.mutateAsync({ id, reason })}
        />
      ) : null}
    </div>
  );
}
