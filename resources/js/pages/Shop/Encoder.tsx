import { Head, Link, router } from '@inertiajs/react';
import { Download, FileSpreadsheet, PackageCheck, Truck } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

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
}

function money(value: string | number) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
}

export default function ShopEncoder({ orders, recent_batches }: Props) {
  const exportCourier = (courierCode: string) => {
    router.post('/shop/exports', { courier_code: courierCode });
  };

  return (
    <AppLayout>
      <Head title="Shop Encoder" />

      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Shop Encoder</h1>
            <p className="text-muted-foreground">Confirmed orders ready for address review and courier export</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => exportCourier('JNT')}>
              <Truck className="mr-2 h-4 w-4" />
              Export J&T
            </Button>
            <Button onClick={() => exportCourier('FLASH')}>
              <FileSpreadsheet className="mr-2 h-4 w-4" />
              Export Flash
            </Button>
          </div>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-3 xl:col-span-2">
            {orders.data.length === 0 ? (
              <Card>
                <CardContent className="py-16 text-center text-muted-foreground">
                  <PackageCheck className="mx-auto mb-3 h-10 w-10 opacity-30" />
                  <p className="font-medium">No orders waiting for encoding</p>
                  <p className="text-sm">Confirmed Shop orders will appear here before courier export.</p>
                </CardContent>
              </Card>
            ) : (
              orders.data.map((order) => (
                <Card key={order.id}>
                  <CardHeader className="pb-3">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                      <div>
                        <CardTitle className="text-base">{order.order_number}</CardTitle>
                        <CardDescription>{order.receiver_name} - {order.receiver_phone}</CardDescription>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">{money(order.total_amount)}</Badge>
                        <Badge variant="secondary">{Number(order.address_confidence ?? 0)}% address</Badge>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-2 text-sm">
                    <p>{order.receiver_address}</p>
                    <p className="text-muted-foreground">
                      {[order.barangay, order.city, order.state].filter(Boolean).join(', ') || 'No structured location'}
                    </p>
                    <p className="text-muted-foreground">{order.product?.name ?? 'No product'}</p>
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
                        <p className="text-xs text-muted-foreground">{batch.courier_code} - {batch.row_count} rows</p>
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
