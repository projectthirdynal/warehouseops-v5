import {
  ColumnDef,
  ColumnFiltersState,
  RowSelectionState,
  SortingState,
  VisibilityState,
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
} from '@tanstack/react-table';
import { useState } from 'react';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ArrowUpDown, ArrowUp, ArrowDown, Columns3 } from 'lucide-react';

interface DataTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  className?: string;
  emptyMessage?: string;
  maxHeight?: number;
  /** Render extra controls (e.g. bulk actions) alongside the column visibility toggle */
  toolbar?: (table: ReturnType<typeof useReactTable<TData>>) => React.ReactNode;
  /** Storage key to persist column visibility in localStorage */
  storageKey?: string;
}

export function DataTable<TData, TValue>({
  columns,
  data,
  className,
  emptyMessage = 'No data.',
  maxHeight,
  toolbar,
  storageKey,
}: DataTableProps<TData, TValue>) {
  const [sorting, setSorting] = useState<SortingState>([]);
  const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
  const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);

  const initialVisibility: VisibilityState = (() => {
    if (storageKey) {
      try {
        const stored = localStorage.getItem(storageKey);
        if (stored) return JSON.parse(stored) as VisibilityState;
      } catch (_) { /* ignore parse/storage errors */ }
    }
    return {};
  })();

  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(initialVisibility);

  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    onSortingChange: setSorting,
    onRowSelectionChange: setRowSelection,
    onColumnVisibilityChange: (updater) => {
      setColumnVisibility((prev) => {
        const next = typeof updater === 'function' ? updater(prev) : updater;
        if (storageKey) {
          try { localStorage.setItem(storageKey, JSON.stringify(next)); } catch (_) { /* ignore storage errors */ }
        }
        return next;
      });
    },
    onColumnFiltersChange: setColumnFilters,
    state: { sorting, rowSelection, columnVisibility, columnFilters },
  });

  const hasToolbarContent = toolbar || columns.some((c) => (c as { enableHiding?: boolean }).enableHiding !== false);

  return (
    <div className={cn('space-y-2', className)}>
      {hasToolbarContent && (
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2 flex-1">
            {toolbar && toolbar(table)}
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="ml-auto h-8 gap-1.5">
                <Columns3 className="h-3.5 w-3.5" />
                <span className="text-xs">Columns</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              <DropdownMenuLabel className="text-xs">Toggle columns</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {table
                .getAllColumns()
                .filter((col) => col.getCanHide())
                .map((col) => (
                  <DropdownMenuCheckboxItem
                    key={col.id}
                    className="text-xs capitalize"
                    checked={col.getIsVisible()}
                    onCheckedChange={(value) => col.toggleVisibility(!!value)}
                  >
                    {col.id.replace(/_/g, ' ')}
                  </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      )}

      <div className={cn('rounded-md border overflow-x-auto')}>
        <div style={maxHeight ? { maxHeight, overflow: 'auto' } : undefined}>
          <Table>
            <TableHeader>
              {table.getHeaderGroups().map((headerGroup) => (
                <TableRow key={headerGroup.id} className="hover:bg-transparent">
                  {headerGroup.headers.map((header) => {
                    const isSorted = header.column.getIsSorted();
                    return (
                      <TableHead
                        key={header.id}
                        className={cn(
                          'text-xs font-semibold text-muted-foreground whitespace-nowrap',
                          header.column.getCanSort() && 'cursor-pointer select-none'
                        )}
                        onClick={header.column.getToggleSortingHandler()}
                      >
                        <div className="flex items-center gap-1">
                          {header.isPlaceholder
                            ? null
                            : flexRender(header.column.columnDef.header, header.getContext())}
                          {header.column.getCanSort() && (
                            <span className="inline-flex">
                              {isSorted === 'asc' && <ArrowUp className="h-3 w-3 text-primary" />}
                              {isSorted === 'desc' && <ArrowDown className="h-3 w-3 text-primary" />}
                              {!isSorted && <ArrowUpDown className="h-3 w-3 text-muted-foreground/50" />}
                            </span>
                          )}
                        </div>
                      </TableHead>
                    );
                  })}
                </TableRow>
              ))}
            </TableHeader>
            <TableBody>
              {table.getRowModel().rows?.length ? (
                table.getRowModel().rows.map((row) => (
                  <TableRow
                    key={row.id}
                    data-state={row.getIsSelected() && 'selected'}
                    className="hover:bg-muted/50"
                  >
                    {row.getVisibleCells().map((cell) => (
                      <TableCell key={cell.id}>
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              ) : (
                <TableRow>
                  <TableCell colSpan={columns.length} className="h-24 text-center text-sm text-muted-foreground">
                    {emptyMessage}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </div>

      {table.getFilteredSelectedRowModel().rows.length > 0 && (
        <p className="text-xs text-muted-foreground">
          {table.getFilteredSelectedRowModel().rows.length} of{' '}
          {table.getFilteredRowModel().rows.length} row(s) selected.
        </p>
      )}
    </div>
  );
}
