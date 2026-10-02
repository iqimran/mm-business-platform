"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { fetchReport, fetchSummary, type ReportName, type ReportQuery } from "./api";

const validRange = (from: string, to: string) => Boolean(from && to && from <= to);

export function useReport<Row, Totals>(name: ReportName, query: ReportQuery) {
  return useQuery({
    queryKey: ["restaurant-reports", name, query],
    queryFn: () => fetchReport<Row, Totals>(name, query),
    enabled: !query.dateFrom || !query.dateTo || validRange(query.dateFrom, query.dateTo),
    placeholderData: keepPreviousData,
  });
}

export function useFinancialSummary(dateFrom: string, dateTo: string, branchId: string) {
  return useQuery({
    queryKey: ["restaurant-reports", "summary", dateFrom, dateTo, branchId],
    queryFn: () => fetchSummary(dateFrom, dateTo, branchId),
    enabled: validRange(dateFrom, dateTo),
    placeholderData: keepPreviousData,
  });
}
