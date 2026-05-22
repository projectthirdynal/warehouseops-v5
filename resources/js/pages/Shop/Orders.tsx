import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  CalendarDays,
  ClipboardList,
  ExternalLink,
  Filter,
  Grid3X3,
  ListFilter,
  PackageCheck,
  Plus,
  RefreshCw,
  Search,
  SlidersHorizontal,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface ShopItem {
  id: number;
  product_name: string;
  quantity: number;
  line_total: string | number;
}

interface ShopOrder {
  id: number;
  order_number: string;
  status: string;
  source_channel: string;
  export_status: string | null;
  encoded_at: string | null;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city?: string | null;
  state?: string | null;
  barangay?: string | null;
  total_amount: string | number;
  shipping_cost?: string | number | null;
  payment_method?: string | null;
  payment_status?: string | null;
  paid_amount?: string | number | null;
  cod_amount?: string | number | null;
  created_at: string;
  customer?: { id: number; name: string; risk_level: string; is_blacklisted: boolean } | null;
  facebook_page?: { id: number; page_name: string } | null;
  product?: { id: number; name: string; sku: string } | null;
  shop_items?: ShopItem[];
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total?: number;
  per_page?: number;
}

interface PageTab {
  id: number;
  page_name: string;
  orders_count: number;
}

interface Props {
  orders: Paginated<ShopOrder>;
  statuses: { value: string; label: string }[];
  status_counts: Record<string, number>;
  channel_counts: Record<string, number>;
  page_tabs: PageTab[];
  filters: {
    q?: string;
    status?: string;
    source_channel?: string;
    fulfillment?: string;
    page_id?: string;
  };
  summary: {
    orders_today: number;
    sales_today: string | number;
    needs_encoding: number;
    open_orders: number;
  };
}

function money(value: string | number | null | undefined) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0));
}

function label(value: string | null | undefined) {
  if (!value) return 'None';
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function dateTime(value: string) {
  return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function shortAddress(order: ShopOrder) {
  return order.receiver_address || [order.barangay, order.city, order.state].filter(Boolean).join(', ') || 'No delivery address';
}

function orderItems(order: ShopOrder) {
  if (order.shop_items && order.shop_items.length > 0) {
    return order.shop_items.map((item) => `${item.product_name} x${item.quantity}`).join(', ');
  }

  return order.product?.name ?? 'No product';
}

function statusClass(status: string) {
  if (['DELIVERED', 'QA_APPROVED', 'CONFIRMED'].includes(status)) return 'bg-emerald-600 text-white border-emerald-600';
  if (['RETURNED', 'CANCELLED', 'QA_REJECTED'].includes(status)) return 'bg-red-600 text-white border-red-600';
  if (['DISPATCHED', 'PROCESSING'].includes(status)) return 'bg-blue-600 text-white border-blue-600';
  return 'bg-cyan-700 text-white border-cyan-700';
}

function tagFor(order: ShopOrder) {
  if (order.source_channel === 'facebook_shop') return 'FB-AUTO';
  if (order.encoded_at) return 'ENCODED';
  return 'POS';
}

export default function ShopOrders({
  orders,
  statuses,
  status_counts,
  channel_counts,
  page_tabs,
  filters,
  summary,
}: Props) {
  const [search, setSearch] = useState(filters.q ?? '');
  const [lastSyncAt, setLastSyncAt] = useState(() => new Date());

  useEffect(() => {
    const interval = window.setInterval(() => {
      if (document.visibilityState !== 'visible') {
        return;
      }

      router.reload({
        only: ['orders', 'summary', 'status_counts', 'channel_counts', 'page_tabs'],
        onSuccess: () => setLastSyncAt(new Date()),
      });
    }, 12000);

    return () => window.clearInterval(interval);
  }, []);

  const updateFilters = (next: Record<string, string | undefined>) => {
    router.get('/shop/orders', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
  };

  const submitSearch = (event: FormEvent) => {
    event.preventDefault();
    updateFilters({ q: search || undefined });
  };

  const clearFilters = () => {
    setSearch('');
    router.get('/shop/orders', {}, { preserveScroll: true });
  };

  const channelTabs = [
    { value: undefined, label: 'All', count: channel_counts.all ?? 0 },
    { value: 'manual_shop', label: 'Manual', count: channel_counts.manual_shop ?? 0 },
    { value: 'walk_in', label: 'Walk-in', count: channel_counts.walk_in ?? 0 },
    { value: 'facebook_shop', label: 'Facebook', count: channel_counts.facebook_shop ?? 0 },
    { value: 'phone_order', label: 'Phone', count: channel_counts.phone_order ?? 0 },
  ];

  const statusTabs = [
    { value: undefined, label: 'All', count: channel_counts.all ?? 0 },
    ...statuses.map((status) => ({
      value: status.value,
      label: status.label,
      count: Number(status_counts[status.value] ?? 0),
    })),
  ];

  return (
    <AppLayout>
      <Head title="Shop Orders" />

      <div className="-m-4 min-h-[calc(100vh-4rem)] bg-slate-100 lg:-m-6">
        <div className="border-b bg-slate-50 px-3 py-2">
          <div className="flex flex-col gap-2 xl:flex-row xl:items-center xl:justify-between">
            <form onSubmit={submitSearch} className="flex min-w-0 max-w-3xl flex-1 gap-2">
              <div className="relative min-w-0 flex-1">
                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Order ID / Receiver name / Address / Phone number / Note"
                  className="h-9 bg-white pl-9"
                />
              </div>
              <Button type="submit" size="sm" variant="outline">Search</Button>
            </form>
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline" className="h-9 rounded-md border-emerald-200 bg-emerald-50 px-3 text-emerald-800">
                Live {lastSyncAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
              </Badge>
              <select className="h-9 rounded-md border border-input bg-white px-3 text-sm">
                <option>All warehouses</option>
              </select>
              <Button asChild size="sm" variant="outline">
                <Link href="/shop/encoder">
                  <PackageCheck className="mr-2 h-4 w-4" />
                  Operations
                </Link>
              </Button>
              <Button asChild size="sm" variant="outline">
                <Link href="/shop/reports">Export</Link>
              </Button>
              <Button asChild size="sm">
                <Link href="/shop/pos">
                  <Plus className="mr-2 h-4 w-4" />
                  Create order
                </Link>
              </Button>
            </div>
          </div>
        </div>

        <div className="border-b bg-slate-100 px-3 py-2">
          <div className="flex flex-wrap gap-2">
            {channelTabs.map((tab) => (
              <Button
                key={tab.label}
                size="sm"
                variant={(filters.source_channel ?? '') === (tab.value ?? '') ? 'default' : 'outline'}
                onClick={() => updateFilters({ source_channel: tab.value, page_id: undefined })}
                className="h-8"
              >
                {tab.label}
                <Badge variant="secondary" className="ml-2 h-5 px-1.5">{tab.count.toLocaleString()}</Badge>
              </Button>
            ))}
          </div>
        </div>

        <div className="border-b bg-white px-3">
          <div className="flex items-center gap-2 overflow-x-auto py-2">
            <button
              onClick={() => updateFilters({ page_id: undefined })}
              className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium ${
                !filters.page_id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'
              }`}
            >
              Order
            </button>
            {page_tabs.map((page) => (
              <button
                key={page.id}
                onClick={() => updateFilters({ page_id: String(page.id), source_channel: 'facebook_shop' })}
                className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium ${
                  filters.page_id === String(page.id) ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'
                }`}
              >
                {page.page_name}
                <span className="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs">{page.orders_count}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="border-b bg-white px-3">
          <div className="flex items-center justify-between gap-3 py-2">
            <div className="flex min-w-0 flex-1 items-center gap-2 overflow-x-auto">
              {statusTabs.map((tab) => (
                <Button
                  key={tab.label}
                  size="sm"
                  variant={(filters.status ?? '') === (tab.value ?? '') ? 'default' : 'ghost'}
                  onClick={() => updateFilters({ status: tab.value })}
                  className="h-8 shrink-0"
                >
                  {tab.label}
                  <span className="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-700">{tab.count.toLocaleString()}</span>
                </Button>
              ))}
            </div>
            <div className="hidden shrink-0 items-center gap-2 md:flex">
              <Button size="icon" variant="outline" className="h-8 w-8" onClick={() => router.reload()}>
                <RefreshCw className="h-4 w-4" />
              </Button>
              <Button size="icon" variant="outline" className="h-8 w-8">
                <CalendarDays className="h-4 w-4" />
              </Button>
              <Button size="icon" variant="outline" className="h-8 w-8">
                <Grid3X3 className="h-4 w-4" />
              </Button>
              <Button size="icon" variant="outline" className="h-8 w-8">
                <ListFilter className="h-4 w-4" />
              </Button>
              <Button size="icon" variant="outline" className="h-8 w-8" onClick={clearFilters}>
                <Filter className="h-4 w-4" />
              </Button>
            </div>
          </div>
        </div>

        <div className="grid gap-3 p-3 lg:grid-cols-4">
          <Card className="rounded-md">
            <CardContent className="flex items-center justify-between p-3">
              <span className="text-sm text-muted-foreground">COD today</span>
              <span className="font-semibold">{money(summary.sales_today)}</span>
            </CardContent>
          </Card>
          <Card className="rounded-md">
            <CardContent className="flex items-center justify-between p-3">
              <span className="text-sm text-muted-foreground">Orders today</span>
              <span className="font-semibold">{summary.orders_today.toLocaleString()}</span>
            </CardContent>
          </Card>
          <Card className="rounded-md">
            <CardContent className="flex items-center justify-between p-3">
              <span className="text-sm text-muted-foreground">Need encoding</span>
              <span className="font-semibold">{summary.needs_encoding.toLocaleString()}</span>
            </CardContent>
          </Card>
          <Card className="rounded-md">
            <CardContent className="flex items-center justify-between p-3">
              <span className="text-sm text-muted-foreground">Open orders</span>
              <span className="font-semibold">{summary.open_orders.toLocaleString()}</span>
            </CardContent>
          </Card>
        </div>

        <div className="px-3 pb-3">
          <div className="overflow-hidden rounded-md border bg-white">
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1320px] text-xs">
                <thead className="bg-slate-200 text-slate-700">
                  <tr className="text-left">
                    <th className="w-8 px-3 py-2"><input type="checkbox" className="h-4 w-4 rounded border-slate-300" /></th>
                    <th className="px-3 py-2 font-semibold">ID</th>
                    <th className="px-3 py-2 font-semibold">Tag</th>
                    <th className="px-3 py-2 font-semibold">Phone number</th>
                    <th className="px-3 py-2 font-semibold">Customer</th>
                    <th className="px-3 py-2 text-right font-semibold">Total</th>
                    <th className="px-3 py-2 font-semibold">Order time</th>
                    <th className="px-3 py-2 font-semibold">Product</th>
                    <th className="px-3 py-2 font-semibold">Source</th>
                    <th className="px-3 py-2 font-semibold">Delivery</th>
                    <th className="px-3 py-2 font-semibold">Created at</th>
                    <th className="px-3 py-2 font-semibold">Status</th>
                    <th className="w-12 px-3 py-2 text-right font-semibold">Open</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.data.length === 0 ? (
                    <tr>
                      <td colSpan={13} className="px-3 py-14 text-center text-sm text-muted-foreground">
                        <ClipboardList className="mx-auto mb-3 h-10 w-10 opacity-30" />
                        No Shop orders found.
                      </td>
                    </tr>
                  ) : (
                    orders.data.map((order) => (
                      <tr key={order.id} className="border-t hover:bg-slate-50">
                        <td className="px-3 py-2 align-middle"><input type="checkbox" className="h-4 w-4 rounded border-slate-300" /></td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle font-medium text-blue-700">{order.order_number}</td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle">
                          <Badge variant="outline" className="rounded-sm border-slate-300 bg-slate-100 px-1.5 py-0 text-[10px] font-medium">
                            {tagFor(order)}
                          </Badge>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle text-blue-700">{order.receiver_phone}</td>
                        <td className="max-w-[180px] truncate px-3 py-2 align-middle">
                          {order.receiver_name}
                          {order.customer?.is_blacklisted && <span className="ml-1 text-red-600">Risk</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-right align-middle font-medium">{money(order.total_amount)}</td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle">
                          {dateTime(order.created_at)}
                          <Badge className="ml-1 h-4 rounded-full bg-cyan-700 px-1.5 text-[10px] text-white">S</Badge>
                        </td>
                        <td className="max-w-[300px] truncate px-3 py-2 align-middle">{orderItems(order)}</td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle">{order.source_channel === 'facebook_shop' ? order.facebook_page?.page_name ?? 'Facebook' : 'POS'}</td>
                        <td className="max-w-[260px] truncate px-3 py-2 align-middle text-muted-foreground">{shortAddress(order)}</td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle">{dateTime(order.created_at)}</td>
                        <td className="whitespace-nowrap px-3 py-2 align-middle">
                          <Badge className={`min-w-[88px] justify-center rounded-md ${statusClass(order.status)}`}>{label(order.status)}</Badge>
                        </td>
                        <td className="px-3 py-2 text-right align-middle">
                          <Button asChild size="icon" variant="ghost" className="h-7 w-7">
                            <Link href={`/shop/orders/${order.id}`}>
                              <ExternalLink className="h-4 w-4" />
                            </Link>
                          </Button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            <div className="flex flex-col gap-2 border-t bg-slate-50 px-3 py-2 text-xs text-muted-foreground md:flex-row md:items-center md:justify-between">
              <div className="flex flex-wrap gap-4">
                <span>COD: <strong className="text-foreground">{money(summary.sales_today)}</strong></span>
                <span>Need encoding: <strong className="text-foreground">{summary.needs_encoding.toLocaleString()}</strong></span>
                <span>Open: <strong className="text-foreground">{summary.open_orders.toLocaleString()}</strong></span>
              </div>
              <div className="flex items-center justify-end gap-1">
                {Array.from({ length: Math.min(orders.last_page, 5) }, (_, index) => index + 1).map((page) => (
                  <Button
                    key={page}
                    size="sm"
                    variant={page === orders.current_page ? 'default' : 'outline'}
                    className="h-7 min-w-7 px-2 text-xs"
                    onClick={() => router.get('/shop/orders', { ...filters, page }, { preserveScroll: true })}
                  >
                    {page}
                  </Button>
                ))}
                {orders.last_page > 5 && <span className="px-2">... {orders.last_page}</span>}
                <Button size="sm" variant="outline" className="h-7 text-xs">
                  <SlidersHorizontal className="mr-1 h-3 w-3" />
                  {orders.per_page ?? 20} / page
                </Button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
