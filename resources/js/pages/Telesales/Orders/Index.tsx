import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import type { Order, PaginatedResponse } from '@/types';
import { Download } from 'lucide-react';

interface Props {
  orders: PaginatedResponse<Order>;
  stats: {
    total: number;
    pending: number;
    qa_pending: number;
    processing: number;
    dispatched: number;
    delivered: number;
    returned: number;
  };
  filters: {
    q?: string;
    status?: string;
    needs_action?: boolean;
  };
}

const statusLabels: Record<string, string> = {
  PENDING: 'Pending',
  CONFIRMED: 'Confirmed',
  QA_PENDING: 'QA Pending',
  QA_APPROVED: 'QA Approved',
  QA_REJECTED: 'QA Rejected',
  PROCESSING: 'Processing',
  DISPATCHED: 'Dispatched',
  DELIVERED: 'Delivered',
  RETURNED: 'Returned',
  CANCELLED: 'Cancelled',
};

const statusClasses: Record<string, string> = {
  PENDING: 'bg-slate-100 text-slate-700',
  CONFIRMED: 'bg-blue-100 text-blue-700',
  QA_PENDING: 'bg-yellow-100 text-yellow-700',
  QA_APPROVED: 'bg-green-100 text-green-700',
  QA_REJECTED: 'bg-red-100 text-red-700',
  PROCESSING: 'bg-indigo-100 text-indigo-700',
  DISPATCHED: 'bg-purple-100 text-purple-700',
  DELIVERED: 'bg-emerald-100 text-emerald-700',
  RETURNED: 'bg-red-100 text-red-700',
  CANCELLED: 'bg-gray-100 text-gray-700',
};

function formatMoney(value: number | string) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(Number(value));
}

export default function TelesalesOrdersIndex({ orders, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.q ?? '');
  const [needsAction, setNeedsAction] = useState(filters.needs_action ?? false);

  const submitSearch = () => {
    router.get(
      '/telesales/orders',
      { ...filters, q: search, needs_action: needsAction || undefined },
      { preserveState: true }
    );
  };

  const setStatus = (status: string) => {
    router.get(
      '/telesales/orders',
      { ...filters, status: status || undefined, needs_action: needsAction ? '1' : undefined },
      { preserveState: true }
    );
  };

  const handleExport = () => {
    const params = new URLSearchParams();
    if (search) params.set('q', search);
    if (filters.status) params.set('status', filters.status);
    if (needsAction) params.set('needs_action', '1');
    window.location.href = `/telesales/orders/export?${params.toString()}`;
  };

  const approve = (order: Order) => {
    if (confirm(`Approve order ${order.order_number}?`)) {
      router.post(`/orders/${order.id}/approve`);
    }
  };

  const reject = (order: Order) => {
    const reason = prompt(`Reject order ${order.order_number}. Reason:`);
    if (reason) {
      router.post(`/orders/${order.id}/reject`, { reason });
    }
  };

  return (
    <TelesalesLayout>
      <Head title="Telesales Orders" />
      <div className="space-y-4 p-4 md:p-6">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Orders</h1>
          <p className="text-sm text-slate-500">
            Centralized telesales order management — ready for approval and checking.
          </p>
        </div>

        <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
          {[
            { label: 'Total', value: stats.total },
            { label: 'Pending', value: stats.pending },
            { label: 'QA Queue', value: stats.qa_pending },
            { label: 'Processing', value: stats.processing },
            { label: 'Dispatched', value: stats.dispatched },
            { label: 'Delivered', value: stats.delivered },
            { label: 'Returned', value: stats.returned },
          ].map((s) => (
            <Card key={s.label}>
              <CardContent className="p-3 text-center">
                <p className="text-xl font-bold text-slate-900">{s.value}</p>
                <p className="text-[10px] uppercase font-semibold text-slate-500">{s.label}</p>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              submitSearch();
            }}
            className="flex-1 min-w-[200px] max-w-sm"
          >
            <Input
              placeholder="Search orders..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </form>

          <select
            value={filters.status ?? ''}
            onChange={(e) => setStatus(e.target.value)}
            className="border rounded-lg px-3 py-2 text-sm bg-white"
          >
            <option value="">All Status</option>
            {Object.entries(statusLabels).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>

          <div className="flex items-center gap-2">
            <Checkbox
              id="needs_action"
              checked={needsAction}
              onCheckedChange={(checked) => {
                setNeedsAction(Boolean(checked));
                setTimeout(submitSearch, 0);
              }}
            />
            <label htmlFor="needs_action" className="text-sm text-slate-700">
              Needs action
            </label>
          </div>

          <Button variant="outline" onClick={handleExport}>
            <Download className="h-4 w-4 mr-2" />
            Export
          </Button>
        </div>

        <div className="bg-white rounded-xl border shadow-sm overflow-hidden">
          <table className="w-full text-sm text-left">
            <thead className="bg-slate-50 text-slate-600 border-b">
              <tr>
                <th className="px-4 py-3 font-medium">Order #</th>
                <th className="px-4 py-3 font-medium">Customer</th>
                <th className="px-4 py-3 font-medium">Product</th>
                <th className="px-4 py-3 font-medium">Total</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Created</th>
                <th className="px-4 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {orders.data.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-12 text-center text-slate-500">
                    No orders found.
                  </td>
                </tr>
              ) : (
                orders.data.map((order) => {
                  const productNames = (order.shop_items ?? [])
                    .map((i) => i.product_name ?? 'Unknown')
                    .join(', ');
                  return (
                    <tr key={order.id} className="border-b last:border-0 hover:bg-slate-50">
                      <td className="px-4 py-3 font-medium text-slate-900">{order.order_number}</td>
                      <td className="px-4 py-3">
                        <p>{order.receiver_name}</p>
                        <p className="text-xs text-slate-500">{order.receiver_phone}</p>
                      </td>
                      <td className="px-4 py-3 max-w-[200px] truncate" title={productNames}>
                        {productNames || '-'}
                      </td>
                      <td className="px-4 py-3">{formatMoney(order.total_amount)}</td>
                      <td className="px-4 py-3">
                        <Badge className={statusClasses[order.status] ?? 'bg-slate-100'}>
                          {statusLabels[order.status] ?? order.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-3 text-slate-500">{order.created_at}</td>
                      <td className="px-4 py-3 text-right space-x-2">
                        <Link href={`/orders/${order.id}`}>
                          <Button size="sm" variant="outline">
                            View
                          </Button>
                        </Link>
                        {order.status === 'QA_PENDING' && (
                          <>
                            <Button size="sm" onClick={() => approve(order)}>
                              Approve
                            </Button>
                            <Button size="sm" variant="destructive" onClick={() => reject(order)}>
                              Reject
                            </Button>
                          </>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </TelesalesLayout>
  );
}
