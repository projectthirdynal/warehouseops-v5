import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  Search,
  ClipboardCheck,
  Clock,
  Truck,
  CheckCircle,
  XCircle,
  Package,
  ChevronRight,
  AlertTriangle,
  RotateCcw,
} from 'lucide-react';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import type { Order, PaginatedResponse } from '@/types';

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
    search?: string;
    status?: string;
    courier?: string;
  };
}

const statusConfig: Record<string, { label: string; color: string; icon: React.ReactNode }> = {
  PENDING:     { label: 'Pending',     color: 'bg-gray-100 text-gray-800',   icon: <Clock className="h-3 w-3" /> },
  CONFIRMED:   { label: 'Confirmed',   color: 'bg-blue-100 text-blue-800',   icon: <CheckCircle className="h-3 w-3" /> },
  QA_PENDING:  { label: 'QA Pending',  color: 'bg-yellow-100 text-yellow-800', icon: <AlertTriangle className="h-3 w-3" /> },
  QA_APPROVED: { label: 'QA Approved', color: 'bg-green-100 text-green-800', icon: <CheckCircle className="h-3 w-3" /> },
  QA_REJECTED: { label: 'QA Rejected', color: 'bg-red-100 text-red-800',     icon: <XCircle className="h-3 w-3" /> },
  PROCESSING:  { label: 'Processing',  color: 'bg-blue-100 text-blue-800',   icon: <Package className="h-3 w-3" /> },
  DISPATCHED:  { label: 'Dispatched',  color: 'bg-indigo-100 text-indigo-800', icon: <Truck className="h-3 w-3" /> },
  DELIVERED:   { label: 'Delivered',   color: 'bg-green-100 text-green-800', icon: <CheckCircle className="h-3 w-3" /> },
  RETURNED:    { label: 'Returned',    color: 'bg-red-100 text-red-800',     icon: <RotateCcw className="h-3 w-3" /> },
  CANCELLED:   { label: 'Cancelled',   color: 'bg-gray-100 text-gray-600',   icon: <XCircle className="h-3 w-3" /> },
};

export default function OrdersIndex({ orders, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.search || '');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.get('/orders', { ...filters, search }, { preserveState: true });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold">Orders</h1>
          <p className="text-sm text-muted-foreground">Order fulfillment pipeline</p>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-3 md:grid-cols-7 gap-3">
          {[
            { label: 'Total', value: stats.total, color: 'text-foreground' },
            { label: 'Pending', value: stats.pending, color: 'text-gray-600' },
            { label: 'QA Queue', value: stats.qa_pending, color: 'text-yellow-600' },
            { label: 'Processing', value: stats.processing, color: 'text-blue-600' },
            { label: 'Dispatched', value: stats.dispatched, color: 'text-indigo-600' },
            { label: 'Delivered', value: stats.delivered, color: 'text-green-600' },
            { label: 'Returned', value: stats.returned, color: 'text-red-600' },
          ].map((s) => (
            <Card key={s.label}>
              <CardContent className="p-3 text-center">
                <p className={`text-xl font-bold ${s.color}`}>{s.value}</p>
                <p className="text-[10px] text-muted-foreground uppercase">{s.label}</p>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-3">
          <form onSubmit={handleSearch} className="flex-1 min-w-[200px] max-w-sm">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Search orders..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-9 pr-4 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/20"
              />
            </div>
          </form>

          <select
            value={filters.status || ''}
            onChange={(e) => router.get('/orders', { ...filters, status: e.target.value || undefined }, { preserveState: true })}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Status</option>
            {Object.entries(statusConfig).map(([key, cfg]) => (
              <option key={key} value={key}>{cfg.label}</option>
            ))}
          </select>

          <select
            value={filters.courier || ''}
            onChange={(e) => router.get('/orders', { ...filters, courier: e.target.value || undefined }, { preserveState: true })}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Couriers</option>
            <option value="FLASH">Flash Express</option>
            <option value="JNT">J&T Express</option>
            <option value="MANUAL">Manual</option>
          </select>
        </div>

        {/* Order list */}
        <div className="space-y-2">
          {orders.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <ClipboardCheck className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p className="font-medium">No orders found</p>
                <p className="text-sm">Orders are created when agents mark leads as ORDERED.</p>
              </CardContent>
            </Card>
          ) : (
            orders.data.map((order) => {
              const cfg = statusConfig[order.status] ?? statusConfig.PENDING;
              return (
                <Link key={order.id} href={`/orders/${order.id}`} className="block">
                  <Card className="hover:bg-accent/30 transition-colors cursor-pointer">
                    <CardContent className="p-4">
                      <div className="flex items-center gap-4">
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-mono text-sm font-semibold text-primary">{order.order_number}</span>
                            <Badge className={`${cfg.color} text-[10px] gap-1`}>{cfg.icon}{cfg.label}</Badge>
                            {order.courier_code && (
                              <Badge variant="outline" className="text-[10px]">{order.courier_code}</Badge>
                            )}
                          </div>
                          <div className="flex items-center gap-3 text-xs text-muted-foreground mt-1">
                            <span>{order.receiver_name}</span>
                            <span>{order.receiver_phone}</span>
                            {order.product && <span>{order.product.name}</span>}
                          </div>
                        </div>

                        <div className="text-right shrink-0">
                          <p className="font-semibold">{formatCurrency(order.total_amount)}</p>
                          <p className="text-xs text-muted-foreground">
                            {order.agent?.name ?? 'Unassigned'}
                          </p>
                        </div>

                        <div className="text-right shrink-0 text-xs text-muted-foreground">
                          {formatDateTime(order.created_at)}
                        </div>

                        <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0" />
                      </div>
                    </CardContent>
                  </Card>
                </Link>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {orders.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: orders.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === orders.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => router.get('/orders', { ...filters, page })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
