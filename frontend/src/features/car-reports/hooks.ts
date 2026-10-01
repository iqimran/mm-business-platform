"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { fetchReport, type ReportName, type ReportParams } from "./api";

export function useReport(name: ReportName, params: ReportParams) {
  return useQuery({
    queryKey: ["car-reports", name, params],
    queryFn: () => fetchReport(name, params),
    placeholderData: keepPreviousData,
  });
}
