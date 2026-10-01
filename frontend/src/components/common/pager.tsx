import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import type { Paginated } from "@/types/api";

export function Pager({ pagination, onPage }: { pagination: Paginated<unknown>["pagination"]; onPage: (page: number) => void }) {
  const { current_page: page, last_page: last, total } = pagination;

  return (
    <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
      <span>
        {total} record{total === 1 ? "" : "s"}
      </span>
      {last > 1 ? (
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon-sm" aria-label="Previous page" disabled={page <= 1} onClick={() => onPage(page - 1)}>
            <ChevronLeft aria-hidden />
          </Button>
          <span className="tabular-nums">
            Page {page} of {last}
          </span>
          <Button variant="outline" size="icon-sm" aria-label="Next page" disabled={page >= last} onClick={() => onPage(page + 1)}>
            <ChevronRight aria-hidden />
          </Button>
        </div>
      ) : null}
    </div>
  );
}
