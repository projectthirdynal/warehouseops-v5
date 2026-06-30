import { useState } from 'react';
import { toast } from 'sonner';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { ArrowLeft, RotateCcw, Search } from 'lucide-react';
import Paginator from '@/components/Paginator';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface TrashedSupply {
  id: number;
  sku: string;
  name: string;
  section: string;
  stock_category?: string;
  opex_category?: string;
  delete_reason?: string;
  deleted_at: string;
  uom?: { name: string; abbreviation: string };
}

interface Props {
  trashed: PaginatedResponse<TrashedSupply>;
  filters: { search?: string };
}

export default function SuppliesTrash({ trashed, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');

  function applyFilters(overrides: Record<string, string>) {
    router.get(
      '/inventory/supplies/trash',
      { ...filters, ...overrides },
      { preserveState: true, replace: true }
    );
  }

  return (
    <AppLayout>
      <Head title="Materials Trash" />
      <div className="space-y-4 p-4 sm:space-y-6 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1">
              <Link
                href="/inventory/supplies"
                className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
              >
                <ArrowLeft className="h-3 w-3" /> Back to Materials
              </Link>
            </div>
            <h1 className="text-2xl font-bold">Materials Trash</h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Soft-deleted materials. Can be restored unless stock is involved in active orders.
            </p>
          </div>
        </div>

        <Card>
          <CardContent className="flex gap-3 p-4">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                applyFilters({ search, page: '1' });
              }}
              className="flex flex-1 gap-2"
            >
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="pl-9"
                  placeholder="Search SKU or name..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                />
              </div>
              <Button type="submit" variant="secondary">
                Search
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Material</TableHead>
                <TableHead>Section</TableHead>
                <TableHead>Deleted</TableHead>
                <TableHead>Reason</TableHead>
                <TableHead className="w-24"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {trashed.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} className="py-12 text-center text-muted-foreground">
                    Trash is empty.
                  </TableCell>
                </TableRow>
              ) : (
                trashed.data.map((item) => (
                  <TableRow key={item.id}>
                    <TableCell>
                      <div className="font-medium">{item.name}</div>
                      <div className="font-mono text-xs text-muted-foreground">{item.sku}</div>
                    </TableCell>
                    <TableCell>
                      <span
                        className={`rounded-full px-2 py-0.5 text-xs font-medium ${item.section === 'OPEX' ? 'bg-info/10 text-info' : 'bg-success/10 text-success'}`}
                      >
                        {item.section}
                      </span>
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                      {formatDate(item.deleted_at)}
                    </TableCell>
                    <TableCell className="max-w-xs text-sm text-muted-foreground truncate">
                      {item.delete_reason ?? '—'}
                    </TableCell>
                    <TableCell>
                      <RestoreButton id={item.id} />
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
          {trashed.last_page > 1 && (
            <div className="border-t p-3">
              <Paginator
                pagination={trashed}
                url="/inventory/supplies/trash"
                params={filters as Record<string, string>}
              />
            </div>
          )}
        </Card>
      </div>
    </AppLayout>
  );
}

function RestoreButton({ id }: { id: number }) {
  const form = useForm({});

  return (
    <Button
      size="sm"
      variant="outline"
      disabled={form.processing}
      onClick={() =>
        form.post(`/inventory/supplies/${id}/restore`, {
          onSuccess: () => toast.success('Material restored.'),
          onError: () => toast.error('Failed to restore material.'),
        })
      }
    >
      <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
      Restore
    </Button>
  );
}
