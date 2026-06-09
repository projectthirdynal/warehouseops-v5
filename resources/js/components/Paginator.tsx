import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import type { PaginatedResponse } from '@/types';

interface Props<T> {
  pagination: PaginatedResponse<T>;
  /** URL to navigate to. Defaults to current window.location.pathname */
  url?: string;
  /** Extra query params to merge (e.g. current filters). Defaults to {} */
  params?: Record<string, string | number | undefined>;
  /** The query-string key for the page number. Defaults to "page" */
  pageKey?: string;
  /** Show "Showing X–Y of N" label. Defaults to true */
  showCount?: boolean;
}

function buildWindow(current: number, last: number): (number | null)[] {
  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1);
  }
  const pages: (number | null)[] = [1];
  const start = Math.max(2, current - 1);
  const end   = Math.min(last - 1, current + 1);
  if (start > 2)    pages.push(null);
  for (let p = start; p <= end; p++) pages.push(p);
  if (end < last - 1) pages.push(null);
  pages.push(last);
  return pages;
}

export default function Paginator<T>({
  pagination,
  url,
  params = {},
  pageKey = 'page',
  showCount = true,
}: Props<T>) {
  const { current_page, last_page, per_page, total } = pagination;

  if (last_page <= 1 && total === 0) return null;

  function go(page: number) {
    const target = url ?? window.location.pathname;
    const merged: Record<string, string> = {};
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== '') merged[k] = String(v);
    }
    merged[pageKey] = String(page);
    router.get(target, merged, { preserveState: true, replace: true });
  }

  const from  = total === 0 ? 0 : (current_page - 1) * per_page + 1;
  const to    = Math.min(current_page * per_page, total);
  const pages = buildWindow(current_page, last_page);

  return (
    <div className="flex items-center justify-between gap-4 px-1">
      {showCount ? (
        <p className="text-xs text-muted-foreground">
          {total === 0 ? 'No results' : `Showing ${from}–${to} of ${total.toLocaleString()}`}
        </p>
      ) : <span />}

      {last_page > 1 && (
        <div className="flex items-center gap-1">
          <Button
            size="sm" variant="outline"
            disabled={current_page <= 1}
            onClick={() => go(current_page - 1)}
          >
            <ArrowLeft className="h-3.5 w-3.5" />
          </Button>

          {pages.map((p, idx) =>
            p === null ? (
              <span key={`ellipsis-${idx}`} className="px-1 text-sm text-muted-foreground select-none">…</span>
            ) : (
              <Button
                key={p}
                size="sm"
                variant={p === current_page ? 'default' : 'outline'}
                className="w-8"
                onClick={() => go(p)}
              >
                {p}
              </Button>
            )
          )}

          <Button
            size="sm" variant="outline"
            disabled={current_page >= last_page}
            onClick={() => go(current_page + 1)}
          >
            <ArrowRight className="h-3.5 w-3.5" />
          </Button>
        </div>
      )}
    </div>
  );
}
