import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Download, FileSpreadsheet, PackageCheck, Truck } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

interface Order {
  id: number;
  order_number: string;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city?: string | null;
  state?: string | null;
  barangay?: string | null;
  total_amount: string | number;
  address_confidence?: string | number | null;
  product?: { id: number; name: string; sku: string } | null;
  shop_items?: { order_id: number; product_name: string; quantity: number }[];
}

interface Batch {
  id: number;
  batch_number: string;
  courier_code: string;
  row_count: number;
  file_path?: string | null;
  created_at: string;
}

interface Paginated<T> {
  data: T[];
}

interface Props {
  orders: Paginated<Order>;
  recent_batches: Batch[];
  couriers: { value: string; label: string }[];
}

function money(value: string | number) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(
    Number(value)
  );
}

function orderSummary(order: Order) {
  if (order.shop_items && order.shop_items.length > 0) {
    return order.shop_items.map((item) => `${item.product_name} x${item.quantity}`).join(', ');
  }

  return order.product?.name ?? 'No product';
}

function AddressEditor({ order }: { order: Order }) {
  const [form, setForm] = useState({
    receiver_address: order.receiver_address ?? '',
    barangay: order.barangay ?? '',
    city: order.city ?? '',
    state: order.state ?? '',
    notes: '',
  });

  const update = (key: keyof typeof form, value: string) =>
    setForm((current) => ({ ...current, [key]: value }));

  return (
    <div className="space-y-3 border-t pt-3">
      <Textarea
        value={form.receiver_address}
        onChange={(event) => update('receiver_address', event.target.value)}
        placeholder="Complete address"
      />
      <div className="grid gap-2 md:grid-cols-3">
        <Input
          value={form.barangay}
          onChange={(event) => update('barangay', event.target.value)}
          placeholder="Barangay"
        />
        <Input
          value={form.city}
          onChange={(event) => update('city', event.target.value)}
          placeholder="City / Municipality"
        />
        <Input
          value={form.state}
          onChange={(event) => update('state', event.target.value)}
          placeholder="Province"
        />
      </div>
      <Input
        value={form.notes}
        onChange={(event) => update('notes', event.target.value)}
        placeholder="Encoder remarks"
      />
      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          variant="outline"
          onClick={() =>
            router.patch(`/shop/encoder/orders/${order.id}/address`, form, { preserveScroll: true })
          }
        >
          Save Address
        </Button>
        <Button
          size="sm"
          onClick={() =>
            router.post(`/shop/encoder/orders/${order.id}/encoded`, {}, { preserveScroll: true })
          }
        >
          Mark Encoded
        </Button>
      </div>
    </div>
  );
}

export default function ShopEncoder({ orders, recent_batches, couriers }: Props) {
  const [selectedOrderIds, setSelectedOrderIds] = useState<number[]>([]);

  const toggleOrder = (orderId: number) => {
    setSelectedOrderIds((current) =>
      current.includes(orderId) ? current.filter((id) => id !== orderId) : [...current, orderId]
    );
  };

  const toggleAll = () => {
    setSelectedOrderIds((current) =>
      current.length === orders.data.length ? [] : orders.data.map((order) => order.id)
    );
  };

  const exportCourier = (courierCode: string) => {
    router.post('/shop/exports', {
      courier_code: courierCode,
      order_ids: selectedOrderIds.length > 0 ? selectedOrderIds : undefined,
    });
  };

  return (
    <AppLayout>
      <Head title="Shop Encoder" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Shop Encoder</h1>
            <p className="text-muted-foreground">
              Confirmed orders ready for address review and courier export
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {orders.data.length > 0 && (
              <Button variant="outline" onClick={toggleAll}>
                {selectedOrderIds.length === orders.data.length ? 'Clear Selection' : 'Select All'}
              </Button>
            )}
            {couriers.map((courier) => (
              <Button
                key={courier.value}
                variant={courier.value === 'FLASH' ? 'default' : 'outline'}
                onClick={() => exportCourier(courier.value)}
              >
                {courier.value === 'JNT' ? (
                  <Truck className="mr-1.5 h-4 w-4" />
                ) : (
                  <FileSpreadsheet className="mr-1.5 h-4 w-4" />
                )}
                Export {courier.label}
              </Button>
            ))}
          </div>
        </div>

        {orders.data.length > 0 && (
          <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
            {selectedOrderIds.length > 0
              ? `${selectedOrderIds.length} selected for the next export.`
              : 'No orders selected. Export buttons will include all encoder-ready orders.'}
          </div>
        )}

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-3 xl:col-span-2">
            {orders.data.length === 0 ? (
              <Card>
                <CardContent className="py-16 text-center text-muted-foreground">
                  <PackageCheck className="mx-auto mb-3 h-10 w-10 opacity-30" />
                  <p className="font-medium">No orders waiting for encoding</p>
                  <p className="text-sm">
                    Confirmed Shop orders will appear here before courier export.
                  </p>
                </CardContent>
              </Card>
            ) : (
              orders.data.map((order) => (
                <Card key={order.id}>
                  <CardHeader className="pb-3">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                      <div className="flex items-start gap-3">
                        <input
                          type="checkbox"
                          className="mt-1 h-4 w-4 rounded border-muted-foreground/40"
                          checked={selectedOrderIds.includes(order.id)}
                          onChange={() => toggleOrder(order.id)}
                          aria-label={`Select ${order.order_number} for export`}
                        />
                        <div>
                          <CardTitle className="text-base">{order.order_number}</CardTitle>
                          <CardDescription>
                            {order.receiver_name} - {order.receiver_phone}
                          </CardDescription>
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">{money(order.total_amount)}</Badge>
                        <Badge variant="secondary">
                          {Number(order.address_confidence ?? 0)}% address
                        </Badge>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-2 text-sm">
                    <p>{order.receiver_address}</p>
                    <p className="text-muted-foreground">
                      {[order.barangay, order.city, order.state].filter(Boolean).join(', ') ||
                        'No structured location'}
                    </p>
                    <p className="text-muted-foreground">{orderSummary(order)}</p>
                    <AddressEditor order={order} />
                  </CardContent>
                </Card>
              ))
            )}
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Recent Export Batches</CardTitle>
              <CardDescription>Generated courier CSV files</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {recent_batches.length === 0 ? (
                <p className="text-sm text-muted-foreground">No export batches yet.</p>
              ) : (
                recent_batches.map((batch) => (
                  <div key={batch.id} className="rounded-lg border p-3">
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <p className="text-sm font-medium">{batch.batch_number}</p>
                        <p className="text-xs text-muted-foreground">
                          {batch.courier_code} - {batch.row_count} rows
                        </p>
                      </div>
                      {batch.file_path && (
                        <Button asChild size="sm" variant="outline">
                          <Link href={`/shop/exports/${batch.id}/download`}>
                            <Download className="h-4 w-4" />
                          </Link>
                        </Button>
                      )}
                    </div>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
