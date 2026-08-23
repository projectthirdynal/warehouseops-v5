import { Head, router } from '@inertiajs/react';
import { Search, CheckCircle2, XCircle, Eye, Clock, Download } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface ShopItem {
  id: number;
  product_name: string;
  sku: string | null;
  quantity: number;
  unit_price: number;
  line_total: number;
  metadata: Record<string, unknown> | null;
}

interface Order {
  id: number;
  order_number: string;
  status: string;
  status_label: string;
  quantity: number;
  unit_price: number;
  total_amount: number;
  cod_amount: number;
  discount_amount: number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  landmark: string | null;
  notes: string | null;
  created_at: string;
  lead: {
    id: number;
    name: string;
    phone: string;
    product_name: string | null;
    product_brand: string | null;
  } | null;
  customer: {
    id: number;
    name: string;
    phone: string;
    total_orders: number;
    successful_orders: number;
    risk_level: string | null;
    is_blacklisted: boolean;
  } | null;
  product: { id: number; name: string; sku: string; brand: string } | null;
  agent: { id: number; name: string } | null;
  shop_items: ShopItem[];
}

interface PaginatedOrders {
  data: Order[];
  current_page: number;
  last_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
  orders: PaginatedOrders;
  filters: { search?: string };
}

function formatDate(d: string) {
  return new Date(d).toLocaleString('en-PH', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export default function CheckerQueue({ orders, filters }: Props) {
  const [search, setSearch] = useState(filters.search || '');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.visit('/checker/queue', {
      data: { search },
      preserveState: true,
      preserveScroll: true,
    });
  };

  const handleExport = () => {
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    window.location.href = `/checker/queue/export?${params.toString()}`;
  };

  const handleApprove = (orderId: number) => {
    if (!confirm('Approve this order? It will be sent to courier fulfillment.')) return;
    fetch(`/api/checker/orders/${orderId}/approve`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN':
          document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
      },
    }).then(() => router.reload());
  };

  const handleReject = (orderId: number) => {
    const reason = prompt('Reason for rejection (required):');
    if (!reason) return;
    fetch(`/api/checker/orders/${orderId}/reject`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
      },
      body: JSON.stringify({ reason }),
    }).then(() => router.reload());
  };

  return (
    <AppLayout>
      <Head title="Sales Review Queue — Checker" />
      <div className="space-y-6 p-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Sales Review Queue</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Review and confirm orders submitted by sales agents. Approved orders go to courier
            fulfillment; rejected orders stay with the assigned agent for correction.
          </p>
        </div>

        {/* Search */}
        <form onSubmit={handleSearch} className="flex gap-3 items-center">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by order #, name, or phone..."
              className="pl-9"
            />
          </div>
          <Button type="submit" variant="outline">
            Search
          </Button>
          <Button variant="outline" onClick={handleExport}>
            <Download className="h-4 w-4 mr-2" />
            Export
          </Button>
        </form>

        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card>
            <CardContent className="flex items-center gap-3 p-4">
              <Clock className="h-8 w-8 text-yellow-500" />
              <div>
                <div className="text-2xl font-bold">{orders.total}</div>
                <div className="text-xs text-muted-foreground">Pending Review</div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Orders Table */}
        <Card>
          <CardHeader>
            <CardTitle>Pending Orders ({orders.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {orders.data.length === 0 ? (
              <p className="text-center text-muted-foreground py-8">
                No orders pending review. Great job!
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-muted-foreground">
                      <th className="pb-2 pr-4">Order #</th>
                      <th className="pb-2 pr-4">Customer</th>
                      <th className="pb-2 pr-4">Product</th>
                      <th className="pb-2 pr-4">Qty</th>
                      <th className="pb-2 pr-4">COD</th>
                      <th className="pb-2 pr-4">Agent</th>
                      <th className="pb-2 pr-4">Address</th>
                      <th className="pb-2 pr-4">Submitted</th>
                      <th className="pb-2 pr-4">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {orders.data.map((order) => (
                      <tr key={order.id} className="border-b hover:bg-muted/50">
                        <td className="py-3 pr-4 font-mono text-xs">{order.order_number}</td>
                        <td className="py-3 pr-4">
                          <div className="font-medium">{order.receiver_name}</div>
                          <div className="text-xs text-muted-foreground">
                            {order.receiver_phone}
                          </div>
                          {order.customer?.is_blacklisted && (
                            <Badge className="bg-red-100 text-red-800 text-xs mt-1">
                              Blacklisted
                            </Badge>
                          )}
                          {order.customer?.risk_level === 'high' && (
                            <Badge className="bg-orange-100 text-orange-800 text-xs mt-1">
                              High Risk
                            </Badge>
                          )}
                        </td>
                        <td className="py-3 pr-4">
                          <div className="text-xs">
                            {order.product?.name || order.lead?.product_name || '—'}
                          </div>
                          {order.product?.brand && (
                            <div className="text-xs text-muted-foreground">
                              {order.product.brand}
                            </div>
                          )}
                        </td>
                        <td className="py-3 pr-4">{order.quantity}</td>
                        <td className="py-3 pr-4 font-medium">₱{order.cod_amount.toFixed(2)}</td>
                        <td className="py-3 pr-4 text-xs">{order.agent?.name || '—'}</td>
                        <td className="py-3 pr-4 text-xs max-w-xs truncate">
                          {order.receiver_address}
                          {order.city && `, ${order.city}`}
                          {order.state && `, ${order.state}`}
                        </td>
                        <td className="py-3 pr-4 text-xs text-muted-foreground">
                          {formatDate(order.created_at)}
                        </td>
                        <td className="py-3 pr-4">
                          <div className="flex gap-1">
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => router.visit(`/checker/queue/${order.id}`)}
                              title="View Details"
                            >
                              <Eye className="h-4 w-4" />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleApprove(order.id)}
                              title="Approve"
                              className="text-green-600"
                            >
                              <CheckCircle2 className="h-4 w-4" />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleReject(order.id)}
                              title="Reject"
                              className="text-red-500"
                            >
                              <XCircle className="h-4 w-4" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Pagination */}
            {orders.last_page > 1 && (
              <div className="flex items-center justify-between mt-4">
                <span className="text-sm text-muted-foreground">
                  Showing {orders.from}–{orders.to} of {orders.total}
                </span>
                <div className="flex gap-1">
                  {orders.links.map((link, i) => (
                    <Button
                      key={i}
                      size="sm"
                      variant={link.active ? 'default' : 'outline'}
                      disabled={!link.url}
                      onClick={() => link.url && router.visit(link.url)}
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
