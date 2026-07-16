import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileText, Pencil, Package, User, Truck, Phone, MapPin } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

interface OrderRemark {
  id: number;
  type: string;
  body: string;
  created_at: string;
  user?: { id: number; name: string } | null;
}

interface OrderItem {
  id: number;
  product_name: string;
  quantity: number;
  unit_price: string | number;
  discount_amount: string | number;
  line_total: string | number;
  product?: { id: number; name: string; sku: string } | null;
  variant?: { id: number; sku: string; variant_name: string } | null;
}

interface Order {
  id: number;
  order_number: string;
  status: string;
  total_amount: string | number;
  cod_amount: string | number;
  shipping_cost: string | number;
  discount_amount: string | number;
  tax_rate: string | number;
  tax_amount: string | number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  courier_code: string | null;
  notes: string | null;
  remarks: string | null;
  created_at: string;
  confirmed_at: string | null;
  delivered_at: string | null;
  customer?: {
    id: number;
    name: string;
    phone: string;
    normalized_phone: string;
    risk_level: string;
    is_blacklisted: boolean;
    canonical_address: string | null;
    barangay: string | null;
    city_municipality: string | null;
    province: string | null;
  } | null;
  shop_items: OrderItem[];
  agent?: { id: number; name: string } | null;
  remarks_entries?: OrderRemark[];
}

interface Props {
  order: Order;
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

export default function OrderShow({ order }: Props) {
  const remarkEntries = order.remarks_entries ?? [];

  return (
    <AppLayout>
      <Head title={`Order ${order.order_number}`} />
      <div className="space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/shop/orders" className="text-muted-foreground hover:text-foreground">
              <ArrowLeft className="h-5 w-5" />
            </Link>
            <div>
              <h1 className="text-2xl font-bold">{order.order_number}</h1>
              <p className="text-sm text-muted-foreground">
                Created {new Date(order.created_at).toLocaleString()}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Badge variant={statusVariant(order.status)} className="text-sm">
              {order.status}
            </Badge>
            <Button asChild variant="outline">
              <Link href={`/shop/orders/${order.id}/edit`}>
                <Pencil className="mr-1 h-4 w-4" />
                Edit
              </Link>
            </Button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <User className="h-4 w-4" />
                Customer
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
              <div className="flex items-center gap-2">
                <Phone className="h-3.5 w-3.5 text-muted-foreground" />
                <span>{order.receiver_phone}</span>
              </div>
              {order.customer && (
                <Link
                  href={`/shop/customers/${order.customer.id}`}
                  className="block font-medium text-info hover:underline"
                >
                  {order.customer.name}
                </Link>
              )}
              {!order.customer && <div className="font-medium">{order.receiver_name}</div>}
              {order.customer?.risk_level && order.customer.risk_level !== 'LOW' && (
                <Badge
                  variant="outline"
                  className={
                    'text-xs ' +
                    (order.customer.risk_level === 'HIGH'
                      ? 'border-destructive/30 text-destructive'
                      : 'border-warning/30 text-warning')
                  }
                >
                  {order.customer.risk_level} Risk
                </Badge>
              )}
              {order.customer?.is_blacklisted && (
                <Badge variant="destructive" className="text-xs">
                  Blacklisted
                </Badge>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <MapPin className="h-4 w-4" />
                Delivery Address
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
              <p>{order.receiver_address || '—'}</p>
              {(order.barangay || order.city || order.state) && (
                <p className="text-muted-foreground">
                  {[order.barangay, order.city, order.state].filter(Boolean).join(', ')}
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Package className="h-4 w-4" />
                Items
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {order.shop_items.map((item) => (
                <div key={item.id} className="flex items-center justify-between text-sm">
                  <div>
                    <span className="font-medium">{item.product_name}</span>
                    <span className="text-muted-foreground"> x{item.quantity}</span>
                  </div>
                  <span className="font-medium">{money(item.line_total)}</span>
                </div>
              ))}
              <div className="border-t pt-2 text-sm">
                <div className="flex justify-between text-muted-foreground">
                  <span>Shipping</span>
                  <span>{money(order.shipping_cost ?? 0)}</span>
                </div>
                {Number(order.discount_amount ?? 0) > 0 && (
                  <div className="flex justify-between text-muted-foreground">
                    <span>Discount</span>
                    <span>−{money(order.discount_amount ?? 0)}</span>
                  </div>
                )}
                {Number(order.tax_amount ?? 0) > 0 && (
                  <div className="flex justify-between text-muted-foreground">
                    <span>Tax ({order.tax_rate}%)</span>
                    <span>{money(order.tax_amount ?? 0)}</span>
                  </div>
                )}
                <div className="flex justify-between font-bold">
                  <span>Total</span>
                  <span>{money(order.total_amount)}</span>
                </div>
                <div className="flex justify-between text-muted-foreground">
                  <span>COD Amount</span>
                  <span>{money(order.cod_amount ?? 0)}</span>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <Truck className="h-4 w-4" />
                Courier & Agent
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-1 text-sm">
              <div>
                <span className="text-muted-foreground">Courier: </span>
                <span className="font-medium">{order.courier_code ?? 'MANUAL'}</span>
              </div>
              <div>
                <span className="text-muted-foreground">Agent: </span>
                <span className="font-medium">{order.agent?.name ?? '—'}</span>
              </div>
              {order.confirmed_at && (
                <div className="text-xs text-muted-foreground">
                  Confirmed: {new Date(order.confirmed_at).toLocaleString()}
                </div>
              )}
              {order.delivered_at && (
                <div className="text-xs text-muted-foreground">
                  Delivered: {new Date(order.delivered_at).toLocaleString()}
                </div>
              )}
            </CardContent>
          </Card>
        </div>

        {(order.remarks || order.notes) && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-base">
                <FileText className="h-4 w-4" />
                Order Remarks
              </CardTitle>
              <CardDescription>Remarks captured during order creation.</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="whitespace-pre-wrap text-sm">{order.remarks || order.notes}</p>
            </CardContent>
          </Card>
        )}

        {remarkEntries.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Remark History</CardTitle>
              <CardDescription>All remark entries with author and timestamp.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {remarkEntries.map((entry) => (
                <div key={entry.id} className="rounded-md border p-3 text-sm">
                  <div className="mb-1 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <Badge variant="outline" className="text-xs">
                        {entry.type}
                      </Badge>
                      <span className="text-xs font-medium">{entry.user?.name ?? 'System'}</span>
                    </div>
                    <span className="text-xs text-muted-foreground">
                      {new Date(entry.created_at).toLocaleString()}
                    </span>
                  </div>
                  <p className="whitespace-pre-wrap">{entry.body}</p>
                </div>
              ))}
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
