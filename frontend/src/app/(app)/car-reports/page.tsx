"use client";

import { FileSpreadsheet, FileText } from "lucide-react";
import { useState } from "react";
import { NativeSelect } from "@/components/common/native-select";
import { Forbidden, PageHeader } from "@/components/common/page-header";
import { Pager } from "@/components/common/pager";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { usePermissions, useSession } from "@/features/auth/hooks";
import { exportReport, type ReportName } from "@/features/car-reports/api";
import { ReportTable, type SortState } from "@/features/car-reports/components/report-table";
import { reports } from "@/features/car-reports/config";
import { useReport } from "@/features/car-reports/hooks";
import { expenseTypeResource } from "@/features/car-master/config";
import { useActiveOptions } from "@/features/car-master/hooks";
import { carStatuses, carStatusLabels } from "@/features/cars/api";
import { CarPicker, type PickedCar } from "@/features/cars/components/car-picker";
import { useDebouncedValue } from "@/hooks/use-debounced-value";
import { errorMessage } from "@/lib/form-errors";

type ExportFormat = "xlsx" | "pdf";

type Filters = {
  page: number;
  branch_id: string;
  from: string;
  to: string;
  state: string;
  status: string;
  settled: string;
  search: string;
  group: string;
  month: string;
  car: PickedCar | null;
  expense_type_id: string;
};

const emptyFilters: Filters = {
  page: 1,
  branch_id: "",
  from: "",
  to: "",
  state: "all",
  status: "",
  settled: "",
  search: "",
  group: "car",
  month: "",
  car: null,
  expense_type_id: "",
};

export default function CarReportsPage() {
  const { can } = usePermissions();
  const { data: session } = useSession();
  const available = (Object.keys(reports) as ReportName[]).filter((n) => can(reports[n].permission));
  const [name, setName] = useState<ReportName>("cars");
  const [filters, setFilters] = useState<Filters>(emptyFilters);
  const [sort, setSort] = useState<SortState | undefined>(reports.cars.defaultSort);

  const current = available.includes(name) ? name : available[0];
  const config = current ? reports[current] : undefined;
  const grouped = filters.group !== "car" && !!config?.groupedColumns;

  const [exporting, setExporting] = useState<ExportFormat | null>(null);
  const [exportError, setExportError] = useState<string>();

  const debouncedSearch = useDebouncedValue(filters.search);
  const usesMonth = !!config?.filters.includes("month") && filters.month !== "";
  const expenseTypes = useActiveOptions(expenseTypeResource, can("car.expense_type.view"));

  const params = {
    page: filters.page,
    per_page: 25,
    branch_id: filters.branch_id,
    // A month replaces the date range (the API rejects both together).
    month: usesMonth ? filters.month : undefined,
    from: config?.filters.includes("period") && !usesMonth ? filters.from : undefined,
    to: config?.filters.includes("period") && !usesMonth ? filters.to : undefined,
    car_id: config?.filters.includes("car") ? filters.car?.id : undefined,
    expense_type_id: config?.filters.includes("expenseType") ? filters.expense_type_id : undefined,
    state: config?.filters.includes("state") ? filters.state : undefined,
    status: config?.filters.includes("status") ? filters.status : undefined,
    settled: config?.filters.includes("settled") ? filters.settled : undefined,
    search: config?.filters.includes("search") ? debouncedSearch : undefined,
    group: grouped ? filters.group : undefined,
    sort: sort?.sort,
    direction: sort?.direction,
  };
  const report = useReport(current ?? "cars", params);

  // Same filters and sort as on screen; the server exports all rows (not just this page).
  const download = async (format: ExportFormat) => {
    if (!current) return;
    setExporting(format);
    setExportError(undefined);
    try {
      await exportReport(current, format, params);
    } catch (e) {
      setExportError(errorMessage(e));
    } finally {
      setExporting(null);
    }
  };

  if (!can("car.report.view") || !config || !current) return <Forbidden />;

  const set = (patch: Partial<Filters>) => setFilters({ ...filters, ...patch, page: 1 });
  const switchTo = (next: ReportName) => {
    setName(next);
    setFilters(emptyFilters);
    setSort(reports[next].defaultSort);
  };
  const branches = session?.branches ?? [];
  const has = (f: (typeof config.filters)[number]) => config.filters.includes(f);

  return (
    <>
      <PageHeader
        title="Car reports"
        description={config.description}
        actions={
          <>
            <Button variant="outline" size="sm" disabled={exporting !== null} onClick={() => download("xlsx")}>
              <FileSpreadsheet aria-hidden />
              {exporting === "xlsx" ? "Exporting…" : "Export Excel"}
            </Button>
            <Button variant="outline" size="sm" disabled={exporting !== null} onClick={() => download("pdf")}>
              <FileText aria-hidden />
              {exporting === "pdf" ? "Exporting…" : "Export PDF"}
            </Button>
          </>
        }
      />
      {exportError ? <p className="text-sm text-destructive">{exportError}</p> : null}

      <div role="tablist" aria-label="Reports" className="flex flex-wrap gap-1 border-b">
        {available.map((n) => (
          <button
            key={n}
            role="tab"
            type="button"
            aria-selected={n === current}
            onClick={() => switchTo(n)}
            className={`-mb-px border-b-2 px-3 py-2 text-sm ${n === current ? "border-primary font-medium" : "border-transparent text-muted-foreground hover:text-foreground"}`}
          >
            {reports[n].title}
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-end gap-3">
        {has("state") ? (
          <NativeSelect aria-label="Sold or unsold" className="w-40" value={filters.state} onChange={(e) => set({ state: e.target.value })}>
            <option value="all">All cars</option>
            <option value="unsold">Unsold (stock)</option>
            <option value="sold">Sold</option>
          </NativeSelect>
        ) : null}
        {has("status") ? (
          <NativeSelect aria-label="Status" className="w-44" value={filters.status} onChange={(e) => set({ status: e.target.value })}>
            <option value="">All statuses</option>
            {carStatuses.map((s) => (
              <option key={s} value={s}>
                {carStatusLabels[s]}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        {has("settled") ? (
          <NativeSelect aria-label="Payment status" className="w-44" value={filters.settled} onChange={(e) => set({ settled: e.target.value })}>
            <option value="">Paid and unpaid</option>
            <option value="no">With due</option>
            <option value="yes">Fully paid</option>
          </NativeSelect>
        ) : null}
        {has("groupParty") || has("groupDealer") ? (
          <NativeSelect aria-label="Group by" className="w-40" value={filters.group} onChange={(e) => set({ group: e.target.value })}>
            <option value="car">Per car</option>
            <option value={has("groupParty") ? "party" : "dealer"}>Per {has("groupParty") ? "party" : "dealer"}</option>
          </NativeSelect>
        ) : null}
        {has("car") ? (
          <div className="flex flex-col gap-1">
            <span className="text-xs">Car</span>
            <CarPicker value={filters.car} onChange={(car) => set({ car })} placeholder="All cars — search to pick one" />
          </div>
        ) : null}
        {has("month") ? (
          <div className="flex flex-col gap-1">
            <Label htmlFor="report-month" className="text-xs">
              Month
            </Label>
            <Input id="report-month" type="month" className="w-44" value={filters.month} onChange={(e) => set({ month: e.target.value })} />
          </div>
        ) : null}
        {has("expenseType") && expenseTypes.data ? (
          <div className="flex flex-col gap-1">
            <span className="text-xs">Expense type</span>
            <NativeSelect aria-label="Expense type" className="w-44" value={filters.expense_type_id} onChange={(e) => set({ expense_type_id: e.target.value })}>
              <option value="">All types</option>
              {expenseTypes.data.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </NativeSelect>
          </div>
        ) : null}
        {has("period") && !usesMonth ? (
          <div className="flex items-end gap-2">
            <div className="flex flex-col gap-1">
              <Label htmlFor="report-from" className="text-xs">
                From
              </Label>
              <Input id="report-from" type="date" className="w-40" value={filters.from} onChange={(e) => set({ from: e.target.value })} />
            </div>
            <div className="flex flex-col gap-1">
              <Label htmlFor="report-to" className="text-xs">
                To
              </Label>
              <Input id="report-to" type="date" className="w-40" value={filters.to} onChange={(e) => set({ to: e.target.value })} />
            </div>
          </div>
        ) : null}
        {branches.length > 1 && (has("branch") || current === "branches") ? (
          <NativeSelect aria-label="Branch" className="w-44" value={filters.branch_id} onChange={(e) => set({ branch_id: e.target.value })}>
            <option value="">All branches</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.code} — {b.name}
              </option>
            ))}
          </NativeSelect>
        ) : null}
        {has("search") ? (
          <Input type="search" placeholder="Search car…" aria-label="Search car" className="w-56" value={filters.search} onChange={(e) => set({ search: e.target.value })} />
        ) : null}
      </div>

      {report.isError ? <p className="text-sm text-destructive">{errorMessage(report.error)}</p> : null}
      {report.isPending ? <p className="text-sm text-muted-foreground">Loading report…</p> : null}

      {report.data ? (
        <>
          <ReportTable
            columns={grouped ? config.groupedColumns! : config.columns}
            rows={report.data.items}
            totals={grouped ? undefined : report.data.totals}
            sort={current === "branches" ? undefined : sort}
            onSort={current === "branches" ? undefined : (s) => setSort(s)}
          />
          {report.data.pagination ? <Pager pagination={report.data.pagination} onPage={(page) => setFilters({ ...filters, page })} /> : null}
        </>
      ) : null}
    </>
  );
}
