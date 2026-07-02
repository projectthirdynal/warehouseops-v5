import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Search, Filter, CheckCircle, XCircle, Clock, Send, AlertCircle } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

interface Invoice {
  id: number;
  ref: string;
  type: string;
  status: string;
  client_name: string;
  total_amount: string;
  amount_paid: string;
  amount_due: string;
  date_invoice: string;
  date_due: string;
  third_party?: { id: number; name: string; type: string } | null;
}

interface Props {
  invoices: {
    data: Invoice[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: { url: string | null; label: string; active: boolean }[];
  };
  filters: {
    status?: string;
    type?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
  };
  statuses: string[];
  types: string[];
}

const statusBadge: Record<string, string> = {
  DRAFT: 'bg-muted text-muted-foreground',
  VALIDATED: 'bg-info/10 text-info',
  SENT: 'bg-primary/10 text-primary',
  PARTIAL: 'bg-warning/10 text-warning',
  PAID: 'bg-success/10 text-success',
  OVERDUE: 'bg-destructive/10 text-destructive',
  CANCELLED: 'bg-muted/80 text-muted-foreground',
};

const statusIcon: Record<string, React.ReactNode> = {
  DRAFT: <Clock className="h-3 w-3" />,
  VALIDATED: <CheckCircle className="h-3 w-3" />,
  SENT: <Send className="h-3 w-3" />,
  PARTIAL: <AlertCircle className="h-3 w-3" />,
  PAID: <CheckCircle className="h-3 w-3" />,
  OVERDUE: <AlertCircle className="h-3 w-3" />,
  CANCELLED: <XCircle className="h-3 w-3" />,
};

export default function InvoiceIndex({ invoices, filters, statuses, types }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [status, setStatus] = useState(filters.status ?? '');
  const [type, setType] = useState(filters.type ?? '');
  const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
  const [dateTo, setDateTo] = useState(filters.date_to ?? '');

  function applyFilters() {
    router.get(
      '/finance/invoices',
      {
        search,
        status,
        type,
        date_from: dateFrom,
        date_to: dateTo,
      },
      { preserveState: true }
    );
  }

  function clearFilters() {
    setSearch('');
    setStatus('');
    setType('');
    setDateFrom('');
    setDateTo('');
    router.get('/finance/invoices', {}, { preserveState: true });
  }

  const totalOutstanding = invoices.data.reduce((sum, i) => sum + parseFloat(i.amount_due), 0);
  const totalPaid = invoices.data.reduce((sum, i) => sum + parseFloat(i.amount_paid), 0);
  const totalAll = invoices.data.reduce((sum, i) => sum + parseFloat(i.total_amount), 0);

  return (
    <AppLayout>
      <Head title="Invoices" />
      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display">Invoices</h1>
            <p className="text-muted-foreground text-sm">{invoices.total} total invoices</p>
          </div>
          <Button asChild>
            <Link href="/finance/invoices/create">
              <Plus className="mr-1.5 h-4 w-4" /> New Invoice
            </Link>
          </Button>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Total Invoiced
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold font-display">
                ₱{totalAll.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Total Paid
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold font-display text-success">
                ₱{totalPaid.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Outstanding
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xl font-bold font-display text-destructive">
                ₱{totalOutstanding.toLocaleString('en-PH', { minimumFractionDigits: 2 })}
              </p>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap gap-3 items-center">
          <div className="relative w-full md:w-72">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search ref, client..."
              className="pl-9"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
            />
          </div>
          <Select
            value={status}
            onValueChange={(v) => {
              setStatus(v);
              applyFilters();
            }}
          >
            <SelectTrigger className="w-40">
              <SelectValue placeholder="Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">All statuses</SelectItem>
              {statuses.map((s) => (
                <SelectItem key={s} value={s}>
                  {s}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select
            value={type}
            onValueChange={(v) => {
              setType(v);
              applyFilters();
            }}
          >
            <SelectTrigger className="w-40">
              <SelectValue placeholder="Type" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">All types</SelectItem>
              {types.map((t) => (
                <SelectItem key={t} value={t}>
                  {t}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Input
            type="date"
            className="w-40"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
          />
          <Input
            type="date"
            className="w-40"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
          />
          <Button variant="outline" size="sm" onClick={applyFilters}>
            <Filter className="mr-1 h-3 w-3" /> Filter
          </Button>
          {(search || status || type || dateFrom || dateTo) && (
            <Button variant="ghost" size="sm" onClick={clearFilters}>
              Clear
            </Button>
          )}
        </div>

        {/* Table */}
        <div className="rounded-md border bg-white">
          <table className="w-full text-sm">
            <thead className="bg-muted/50">
              <tr>
                <th className="px-4 py-3 text-left font-medium">Ref</th>
                <th className="px-4 py-3 text-left font-medium">Client</th>
                <th className="px-4 py-3 text-left font-medium">Date</th>
                <th className="px-4 py-3 text-left font-medium">Due</th>
                <th className="px-4 py-3 text-right font-medium">Total</th>
                <th className="px-4 py-3 text-right font-medium">Paid</th>
                <th className="px-4 py-3 text-right font-medium">Due</th>
                <th className="px-4 py-3 text-center font-medium">Status</th>
                <th className="px-4 py-3 text-center font-medium">Type</th>
              </tr>
            </thead>
            <tbody>
              {invoices.data.length === 0 && (
                <tr>
                  <td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">
                    No invoices found.
                  </td>
                </tr>
              )}
              {invoices.data.map((inv) => (
                <tr
                  key={inv.id}
                  className="border-t hover:bg-muted/30 cursor-pointer"
                  onClick={() => router.visit(`/finance/invoices/${inv.id}`)}
                >
                  <td className="px-4 py-3 font-medium">{inv.ref}</td>
                  <td className="px-4 py-3">{inv.client_name}</td>
                  <td className="px-4 py-3">{inv.date_invoice}</td>
                  <td className="px-4 py-3">{inv.date_due ?? '—'}</td>
                  <td className="px-4 py-3 text-right">
                    ₱
                    {parseFloat(inv.total_amount).toLocaleString('en-PH', {
                      minimumFractionDigits: 2,
                    })}
                  </td>
                  <td className="px-4 py-3 text-right">
                    ₱
                    {parseFloat(inv.amount_paid).toLocaleString('en-PH', {
                      minimumFractionDigits: 2,
                    })}
                  </td>
                  <td className="px-4 py-3 text-right">
                    ₱
                    {parseFloat(inv.amount_due).toLocaleString('en-PH', {
                      minimumFractionDigits: 2,
                    })}
                  </td>
                  <td className="px-4 py-3 text-center">
                    <Badge className={`${statusBadge[inv.status] ?? 'bg-muted'} gap-1`}>
                      {statusIcon[inv.status]} {inv.status}
                    </Badge>
                  </td>
                  <td className="px-4 py-3 text-center capitalize">{inv.type}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {invoices.last_page > 1 && (
          <div className="flex items-center justify-between">
            <p className="text-sm text-muted-foreground">
              Page {invoices.current_page} of {invoices.last_page}
            </p>
            <div className="flex gap-1">
              {invoices.links.map((link, i) => (
                <Button
                  key={i}
                  variant={link.active ? 'default' : 'outline'}
                  size="sm"
                  disabled={!link.url}
                  onClick={() => link.url && router.visit(link.url)}
                  dangerouslySetInnerHTML={{ __html: link.label }}
                  className="min-w-[2.5rem]"
                />
              ))}
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
