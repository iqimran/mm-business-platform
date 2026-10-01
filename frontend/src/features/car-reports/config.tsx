import Link from "next/link";
import { carStatusLabels, type CarStatus } from "@/features/cars/api";
import type { ReportName, ReportRow } from "./api";
import { valueAt, type Column } from "./components/report-table";

const carCell = (row: ReportRow) => {
  const car = row.car as { id: string; brand: string; model: string; chassis_number: string; registration_number: string | null };
  return (
    <div>
      <Link href={`/cars/${car.id}`} className="font-medium underline-offset-4 hover:underline">
        {car.brand} {car.model}
      </Link>
      <div className="text-xs text-muted-foreground">{car.registration_number ?? car.chassis_number}</div>
    </div>
  );
};

const statusCell = (row: ReportRow) => carStatusLabels[row.status as CarStatus] ?? String(row.status);
const name = (path: string) => (row: ReportRow) => (valueAt(row, path) as string | undefined) ?? "—";

export type ReportConfig = {
  title: string;
  description: string;
  permission: string;
  defaultSort?: { sort: string; direction: "asc" | "desc" };
  /** Filters shown for this report. */
  filters: ("branch" | "period" | "month" | "car" | "expenseType" | "state" | "status" | "settled" | "search" | "groupParty" | "groupDealer")[];
  columns: Column[];
  groupedColumns?: Column[];
};

export const reports: Record<ReportName, ReportConfig> = {
  cars: {
    title: "Cars",
    description: "Inventory and sold/unsold register with each car's figures.",
    permission: "car.view",
    defaultSort: { sort: "brand", direction: "asc" },
    filters: ["state", "status", "branch", "search"],
    columns: [
      { key: "car", label: "Car", sort: "brand", render: carCell, total: "cars" },
      { key: "branch", label: "Branch", render: name("branch.code"), hideBelow: "md" },
      { key: "status", label: "Status", sort: "status", render: statusCell },
      { key: "days_in_stock", label: "Days in stock", sort: "days_in_stock", align: "right", hideBelow: "lg" },
      { key: "total_investment", label: "Investment", sort: "total_investment", money: true, total: "total_investment" },
      { key: "sale_amount", label: "Sale", money: true, total: "sale_amount", hideBelow: "md" },
      { key: "profit", label: "Profit", sort: "profit", money: true, total: "profit" },
      { key: "party_due", label: "Party due", money: true, total: "party_due", hideBelow: "lg" },
      { key: "dealer_payable", label: "Dealer payable", money: true, total: "dealer_payable", hideBelow: "lg" },
    ],
  },
  sales: {
    title: "Sales & profit",
    description: "Cars sold in the period: price, cost, profit and collection.",
    permission: "car.sale.view",
    defaultSort: { sort: "sale_date", direction: "desc" },
    filters: ["period", "settled", "branch", "search"],
    columns: [
      { key: "sale_date", label: "Sold on", sort: "sale_date", total: "cars", render: (r) => String(r.sale_date) },
      { key: "car", label: "Car", sort: "brand", render: carCell },
      { key: "party", label: "Party", render: name("party.name"), hideBelow: "md" },
      { key: "sale_amount", label: "Sale price", sort: "sale_amount", money: true, total: "sale_amount" },
      { key: "total_investment", label: "Cost", money: true, total: "total_investment", hideBelow: "md" },
      { key: "profit", label: "Profit", sort: "profit", money: true, total: "profit" },
      { key: "party_received", label: "Received", money: true, total: "party_received", hideBelow: "lg" },
      { key: "party_due", label: "Due", sort: "party_due", money: true, total: "party_due" },
    ],
  },
  receivables: {
    title: "Receivables",
    description: "What customers still owe (party due = sale amount − payments received).",
    permission: "car.sale.view",
    defaultSort: { sort: "amount", direction: "desc" },
    filters: ["groupParty", "branch", "search"],
    columns: [
      { key: "party", label: "Party", render: name("party.name"), total: "cars" },
      { key: "car", label: "Car", sort: "brand", render: carCell },
      { key: "sale_date", label: "Sold on", sort: "date", render: (r) => String(r.sale_date), hideBelow: "md" },
      { key: "days_outstanding", label: "Days", align: "right", hideBelow: "md" },
      { key: "sale_amount", label: "Sale", money: true, total: "sale_amount", hideBelow: "lg" },
      { key: "party_received", label: "Received", money: true, total: "party_received", hideBelow: "lg" },
      { key: "party_due", label: "Due", sort: "amount", money: true, total: "party_due" },
    ],
    groupedColumns: [
      { key: "party", label: "Party", render: name("party.name") },
      { key: "cars", label: "Cars", sort: "brand", align: "right" },
      { key: "oldest_date", label: "Oldest sale", sort: "date" },
      { key: "original", label: "Sales", money: true },
      { key: "settled", label: "Received", money: true },
      { key: "outstanding", label: "Due", sort: "amount", money: true },
    ],
  },
  payables: {
    title: "Payables",
    description: "What we still owe dealers (dealer payable = purchase amount − payments made).",
    permission: "car.dealer_payment.view",
    defaultSort: { sort: "amount", direction: "desc" },
    filters: ["groupDealer", "branch", "search"],
    columns: [
      { key: "dealer", label: "Dealer", render: name("dealer.name"), total: "cars" },
      { key: "car", label: "Car", sort: "brand", render: carCell },
      { key: "purchase_date", label: "Bought on", sort: "date", render: (r) => String(r.purchase_date ?? "—"), hideBelow: "md" },
      { key: "purchase_cost", label: "Purchase", money: true, total: "purchase_cost", hideBelow: "lg" },
      { key: "dealer_paid", label: "Paid", money: true, total: "dealer_paid", hideBelow: "lg" },
      { key: "dealer_payable", label: "Payable", sort: "amount", money: true, total: "dealer_payable" },
    ],
    groupedColumns: [
      { key: "dealer", label: "Dealer", render: name("dealer.name") },
      { key: "cars", label: "Cars", sort: "brand", align: "right" },
      { key: "oldest_date", label: "Oldest purchase", sort: "date" },
      { key: "original", label: "Purchases", money: true },
      { key: "settled", label: "Paid", money: true },
      { key: "outstanding", label: "Payable", sort: "amount", money: true },
    ],
  },
  expenses: {
    title: "Expenses",
    description: "Car expenses for a month, a date range or one car (reversed entries excluded).",
    permission: "car.expense.view",
    defaultSort: { sort: "expense_date", direction: "desc" },
    filters: ["month", "period", "car", "expenseType", "branch"],
    columns: [
      { key: "expense_date", label: "Date", sort: "expense_date", total: "entries" },
      { key: "expense_type", label: "Type", render: name("expense_type.name") },
      { key: "car", label: "Car", render: carCell },
      { key: "description", label: "Description", hideBelow: "lg" },
      { key: "branch", label: "Branch", render: name("branch.code"), hideBelow: "md" },
      { key: "amount", label: "Amount", sort: "amount", money: true, total: "total" },
    ],
  },
  branches: {
    title: "Branches",
    description: "Branch comparison. Stock, due and payable are current; sales, profit and expenses use the period.",
    permission: "car.view",
    filters: ["period"],
    columns: [
      { key: "branch", label: "Branch", render: (r) => `${valueAt(r, "branch.code")} — ${valueAt(r, "branch.name")}` },
      { key: "stock_cars", label: "In stock", align: "right", total: "stock_cars" },
      { key: "stock_investment", label: "Stock investment", money: true, total: "stock_investment" },
      { key: "expenses", label: "Expenses", money: true, total: "expenses", hideBelow: "md" },
      { key: "sold_cars", label: "Sold", align: "right", total: "sold_cars" },
      { key: "sales_total", label: "Sales", money: true, total: "sales_total" },
      { key: "profit", label: "Profit", money: true, total: "profit" },
      { key: "party_due", label: "Party due", money: true, total: "party_due", hideBelow: "lg" },
      { key: "dealer_payable", label: "Dealer payable", money: true, total: "dealer_payable", hideBelow: "lg" },
    ],
  },
};
