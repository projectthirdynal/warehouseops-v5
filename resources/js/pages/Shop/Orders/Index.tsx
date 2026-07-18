import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Search, Plus, FileText, MessageSquare } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface OrderRow {
  id: number;
  order_number: string;
  status: string;
  total_amount: string | number;
  cod_amount: string | number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  courier_code: string | null;
  remarks: string | null;
  notes: string | null;
  created_at: string;
  customer?: { id: number; name: string; phone: string } | null;
  shop_items?: { order_id: number; product_name: string; quantity: number }[];
  agent?: { id: number; name: string } | null;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
  orders: Paginated<OrderRow>;
  filters: {
    q?: string;
    status?: string;
    remark_q?: string;
    remark_type?: string;
    remark_author?: string;
  };
  remarkAuthors?: Record<string, string>;
}

function money(value: string | number) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(Number(value));
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
  const s = status.toUpperCase();
  if (s === 'DELIVERED') return 'default';
  if (s === 'RETURNED' || s === 'CANCELLED' || s === 'QA_REJECTED') return 'destructive';
  if (s === 'CONFIRMED' || s === 'QA_APPROVED') return 'secondary';
  return 'outline';
}

export default function OrdersIndex({ orders, filters, remarkAuthors = {} }: Props) {
  const [search, setSearch] = useState(filters.q ?? '');
  const [statusFilter, setStatusFilter] = useState(filters.status ?? '');
  const [remarkSearch, setRemarkSearch] = useState(filters.remark_q ?? '');
  const [remarkType, setRemarkType] = useState(filters.remark_type ?? '');
  const [remarkAuthor, setRemarkAuthor] = useState(filters.remark_author ?? '');
  const [showRemarkFilters, setShowRemarkFilters] = useState(
    Boolean(filters.remark_q || filters.remark_type || filters.remark_author)
  );

  const orderSummary = (order: OrderRow) => {
    if (order.shop_items && order.shop_items.length > 0) {
      return order.shop_items.map((item) => `${item.product_name} x${item.quantity}`).join(', ');
    }
    return '—';
  };

  return (
    <AppLayout>
      <Head title="Shop Orders" />
      <div className="space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-bold">Shop Orders</h1>
          <Button asChild>
            <Link href="/shop/orders/create">
              <Plus className="mr-1 h-4 w-4" />
              New Order
            </Link>
          </Button>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Filters</CardTitle>
          </CardHeader>
          <CardContent>
            <form className="flex flex-wrap gap-3" method="GET" action="/shop/orders">
              <div className="relative flex-1 min-w-[200px]">
                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  name="q"
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder="Search order #, name, or phone"
                  className="pl-8"
                />
              </div>
              <select
                name="status"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
                className="flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              >
                <option value="">All statuses</option>
                <option value="PENDING">Pending</option>
                <option value="CONFIRMED">Confirmed</option>
                <option value="QA_APPROVED">QA Approved</option>
                <option value="DELIVERED">Delivered</option>
                <option value="RETURNED">Returned</option>
                <option value="CANCELLED">Cancelled</option>
              </select>
              <Button type="submit">Filter</Button>
              <Button
                type="button"
                variant={showRemarkFilters ? 'secondary' : 'ghost'}
                size="sm"
                onClick={() => setShowRemarkFilters(!showRemarkFilters)}
              >
                <MessageSquare className="mr-1 h-4 w-4" />
                Remark Search
              </Button>
            </form>
            {showRemarkFilters && (
              <form
                className="mt-3 flex flex-wrap gap-3 rounded-md border bg-muted/30 p-3"
                method="GET"
                action="/shop/orders"
              >
                <input type="hidden" name="q" value={search} />
                <input type="hidden" name="status" value={statusFilter} />
                <div className="relative flex-1 min-w-[200px]">
                  <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                  <Input
                    name="remark_q"
                    value={remarkSearch}
                    onChange={(e) => setRemarkSearch(e.target.value)}
                    placeholder="Search remark content..."
                    className="pl-8"
                  />
                </div>
                <select
                  name="remark_type"
                  value={remarkType}
                  onChange={(e) => setRemarkType(e.target.value)}
                  className="flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <option value="">All remark types</option>
                  <option value="agent_note">Agent Note</option>
                  <option value="follow_up">Follow-up</option>
                  <option value="escalation">Escalation</option>
                  <option value="customer_feedback">Customer Feedback</option>
                  <option value="system">System</option>
                  <option value="duplicate_warning">Duplicate Warning</option>
                  <option value="conversation_source">Conversation Source</option>
                </select>
                <select
                  name="remark_author"
                  value={remarkAuthor}
                  onChange={(e) => setRemarkAuthor(e.target.value)}
                  className="flex h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <option value="">All authors</option>
                  {Object.entries(remarkAuthors).map(([id, name]) => (
                    <option key={id} value={id}>
                      {name}
                    </option>
                  ))}
                </select>
                <Button type="submit" size="sm">
                  Search Remarks
                </Button>
              </form>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2">Order #</th>
                    <th className="px-3 py-2">Customer</th>
                    <th className="px-3 py-2">Items</th>
                    <th className="px-3 py-2">Total</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2">Remarks</th>
                    <th className="px-3 py-2">Agent</th>
                    <th className="px-3 py-2">Date</th>
                  </tr>
                </thead>
                <tbody>
                  {orders.data.length === 0 && (
                    <tr>
                      <td colSpan={8} className="px-3 py-8 text-center text-muted-foreground">
                        No orders found.
                      </td>
                    </tr>
                  )}
                  {orders.data.map((order) => (
                    <tr key={order.id} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="px-3 py-2">
                        <Link
                          href={`/shop/orders/${order.id}`}
                          className="font-medium text-info hover:underline"
                        >
                          {order.order_number}
                        </Link>
                      </td>
                      <td className="px-3 py-2">
                        <div className="font-medium">{order.receiver_name}</div>
                        <div className="text-xs text-muted-foreground">{order.receiver_phone}</div>
                      </td>
                      <td className="px-3 py-2 max-w-[200px] truncate text-muted-foreground">
                        {orderSummary(order)}
                      </td>
                      <td className="px-3 py-2 font-medium">{money(order.total_amount)}</td>
                      <td className="px-3 py-2">
                        <Badge variant={statusVariant(order.status)}>{order.status}</Badge>
                      </td>
                      <td className="px-3 py-2 max-w-[200px]">
                        {order.remarks || order.notes ? (
                          <div className="flex items-start gap-1 text-xs text-muted-foreground">
                            <FileText className="mt-0.5 h-3 w-3 shrink-0" />
                            <span className="line-clamp-2">{order.remarks || order.notes}</span>
                          </div>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-xs text-muted-foreground">
                        {order.agent?.name ?? '—'}
                      </td>
                      <td className="px-3 py-2 text-xs text-muted-foreground">
                        {new Date(order.created_at).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {orders.last_page > 1 && (
              <div className="flex items-center justify-between border-t px-3 py-2 text-xs">
                <span className="text-muted-foreground">
                  Showing {orders.from ?? 0}–{orders.to ?? 0} of {orders.total}
                </span>
                <div className="flex gap-1">
                  {orders.links.map((link, i) => (
                    <Link
                      key={i}
                      href={link.url ?? '#'}
                      className={`rounded px-2 py-1 ${link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
