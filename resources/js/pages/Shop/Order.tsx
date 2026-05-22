import { FormEvent, useMemo } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  CalendarDays,
  Copy,
  Expand,
  Link as LinkIcon,
  MessageSquare,
  Package,
  Plus,
  Printer,
  Save,
  Truck,
  Trash2,
  UserRound,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface ShopItem {
  id: number;
  product_id?: number | null;
  variant_id?: number | null;
  sku?: string | null;
  product_name: string;
  quantity: number;
  unit_price: string | number;
  discount_amount: string | number;
  line_total: string | number;
}

interface ProductVariant {
  id: number;
  sku: string;
  variant_name: string;
  selling_price: string | number | null;
  available_stock: number | null;
  is_low_stock: boolean;
}

interface Product {
  id: number;
  sku: string;
  name: string;
  selling_price: string | number;
  available_stock: number | null;
  is_low_stock: boolean;
  active_variants: ProductVariant[];
}

interface EditableItem {
  product_id: string;
  variant_id: string;
  product_name: string;
  quantity: string;
  unit_price: string;
}

interface Remark {
  id: number;
  type: string;
  body: string;
  created_at: string;
  user?: { id: number; name: string } | null;
}

interface ShopOrder {
  id: number;
  order_number: string;
  status: string;
  courier_code?: string | null;
  source_channel: string;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  barangay?: string | null;
  city?: string | null;
  state?: string | null;
  postal_code?: string | null;
  quantity: number;
  unit_price: string | number;
  total_amount: string | number;
  cod_amount: string | number;
  shipping_cost?: string | number | null;
  payment_method?: string | null;
  payment_status?: string | null;
  paid_amount?: string | number | null;
  discount_amount?: string | number | null;
  surcharge_amount?: string | number | null;
  notes?: string | null;
  address_confidence?: string | number | null;
  created_at: string;
  encoded_at?: string | null;
  customer?: {
    id: number;
    name: string;
    phone: string;
    normalized_phone?: string | null;
    facebook_name?: string | null;
    canonical_address?: string | null;
    total_orders: number;
    successful_orders: number;
    returned_orders: number;
    success_rate: string | number;
    total_revenue: string | number;
    risk_level: string;
    is_blacklisted: boolean;
    blacklist_reason?: string | null;
    last_order_date?: string | null;
  } | null;
  facebook_page?: { id: number; page_name: string; page_id: string } | null;
  shop_items: ShopItem[];
  agent?: { id: number; name: string } | null;
  remarks: Remark[];
}

interface Props {
  order: ShopOrder;
  products: Product[];
  statuses: { value: string; label: string }[];
  agents: { id: number; name: string; role: string }[];
  couriers: { value: string; label: string }[];
}

function money(value: string | number | null | undefined) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0));
}

function numeric(value: string | number | null | undefined) {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function statusClass(status: string) {
  if (['DELIVERED', 'QA_APPROVED', 'CONFIRMED'].includes(status)) return 'bg-emerald-600 text-white';
  if (['RETURNED', 'CANCELLED', 'QA_REJECTED'].includes(status)) return 'bg-red-600 text-white';
  if (['DISPATCHED', 'PROCESSING'].includes(status)) return 'bg-blue-600 text-white';
  return 'bg-cyan-700 text-white';
}

function sourceLabel(order: ShopOrder) {
  if (order.source_channel === 'facebook_shop') {
    return order.facebook_page?.page_name ?? 'Facebook CRM';
  }

  return 'POS';
}

function emptyItem(): EditableItem {
  return {
    product_id: '',
    variant_id: '',
    product_name: '',
    quantity: '1',
    unit_price: '',
  };
}

function itemFromOrder(item: ShopItem): EditableItem {
  return {
    product_id: item.product_id ? String(item.product_id) : '',
    variant_id: item.variant_id ? String(item.variant_id) : '',
    product_name: item.product_name ?? '',
    quantity: String(item.quantity),
    unit_price: String(item.unit_price ?? ''),
  };
}

export default function ShopOrder({ order, products, statuses, agents, couriers }: Props) {
  const { data, setData, patch, processing, errors } = useForm({
    assigned_agent_id: order.agent?.id ? String(order.agent.id) : '',
    status: order.status,
    courier_code: order.courier_code ?? 'MANUAL',
    receiver_name: order.receiver_name ?? '',
    receiver_phone: order.receiver_phone ?? '',
    receiver_address: order.receiver_address ?? '',
    barangay: order.barangay ?? '',
    city: order.city ?? '',
    state: order.state ?? '',
    postal_code: order.postal_code ?? '',
    shipping_cost: String(order.shipping_cost ?? 0),
    payment_method: order.payment_method ?? 'COD',
    paid_amount: String(order.paid_amount ?? 0),
    discount_amount: String(order.discount_amount ?? 0),
    surcharge_amount: String(order.surcharge_amount ?? 0),
    notes: order.notes ?? '',
    internal_note: '',
    items: order.shop_items.length > 0 ? order.shop_items.map(itemFromOrder) : [emptyItem()],
  });

  const itemsTotal = useMemo(
    () => data.items.reduce((total, item) => total + Math.max(1, Number(item.quantity || 1)) * numeric(item.unit_price), 0),
    [data.items]
  );

  const shippingFee = numeric(data.shipping_cost);
  const paidAmount = numeric(data.paid_amount);
  const discountAmount = numeric(data.discount_amount);
  const surchargeAmount = numeric(data.surcharge_amount);
  const orderTotal = Math.max(0, itemsTotal + shippingFee + surchargeAmount - discountAmount);
  const amountDue = Math.max(0, orderTotal - paidAmount);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    patch(`/shop/orders/${order.id}`, { preserveScroll: true });
  };

  const updateItem = (index: number, field: keyof EditableItem, value: string) => {
    setData('items', data.items.map((item, itemIndex) => (
      itemIndex === index ? { ...item, [field]: value } : item
    )));
  };

  const chooseProduct = (index: number, productId: string) => {
    const product = products.find((item) => String(item.id) === productId);

    setData('items', data.items.map((item, itemIndex) => (
      itemIndex === index
        ? {
            ...item,
            product_id: productId,
            variant_id: '',
            product_name: product ? product.name : item.product_name,
            unit_price: product ? String(product.selling_price) : '',
          }
        : item
    )));
  };

  const chooseVariant = (index: number, variantId: string) => {
    const currentItem = data.items[index];
    const selectedProduct = products.find((product) => String(product.id) === currentItem.product_id);
    const variant = selectedProduct?.active_variants.find((item) => String(item.id) === variantId);

    setData('items', data.items.map((item, itemIndex) => (
      itemIndex === index
        ? {
            ...item,
            variant_id: variantId,
            unit_price: variant?.selling_price ? String(variant.selling_price) : item.unit_price,
          }
        : item
    )));
  };

  const addItem = () => {
    setData('items', [...data.items, emptyItem()]);
  };

  const removeItem = (index: number) => {
    setData('items', data.items.length === 1 ? [emptyItem()] : data.items.filter((_, itemIndex) => itemIndex !== index));
  };

  const activeItems = data.items.filter((item) => item.product_id);
  const totalQuantity = data.items.reduce((total, item) => total + (item.product_id ? Math.max(1, Number(item.quantity || 1)) : 0), 0);
  const formErrors = errors as Record<string, string | undefined>;

  return (
    <AppLayout>
      <Head title={`Shop Order ${order.order_number}`} />

      <form onSubmit={submit} className="-m-4 min-h-[calc(100vh-4rem)] bg-slate-100 pb-20 lg:-m-6">
        <div className="sticky top-0 z-20 border-b bg-white px-4 py-2">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex min-w-0 items-center gap-3">
              <Button asChild variant="ghost" size="icon">
                <Link href="/shop/orders">
                  <ArrowLeft className="h-4 w-4" />
                </Link>
              </Button>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h1 className="text-base font-bold">#{order.order_number}</h1>
                  <Badge className={statusClass(order.status)}>{order.status}</Badge>
                  <Button type="button" variant="ghost" size="icon" className="h-7 w-7">
                    <LinkIcon className="h-4 w-4" />
                  </Button>
                  <Button type="button" variant="ghost" size="icon" className="h-7 w-7">
                    <Copy className="h-4 w-4" />
                  </Button>
                </div>
                <p className="text-xs text-muted-foreground">{sourceLabel(order)} - Created {new Date(order.created_at).toLocaleString()}</p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Label className="text-xs">Assignee</Label>
              <select
                value={data.assigned_agent_id}
                onChange={(event) => setData('assigned_agent_id', event.target.value)}
                className="h-9 rounded-md border border-input bg-background px-3 text-sm"
              >
                <option value="">Choose an employee</option>
                {agents.map((agent) => (
                  <option key={agent.id} value={agent.id}>{agent.name}</option>
                ))}
              </select>
              <Button asChild type="button" size="icon" variant="outline" className="h-9 w-9">
                <Link href="/shop/orders">
                  <X className="h-4 w-4" />
                </Link>
              </Button>
            </div>
          </div>
        </div>

        <div className="grid gap-3 p-3 xl:grid-cols-[1fr_365px]">
          <div className="space-y-3">
            <Card className="rounded-md">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 py-3">
                <CardTitle className="text-sm">Product</CardTitle>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant="secondary">{sourceLabel(order)}</Badge>
                  <Badge variant="outline">{order.courier_code ?? 'MANUAL'}</Badge>
                  <Badge variant={order.encoded_at ? 'default' : 'outline'}>{order.encoded_at ? 'Encoded' : 'Not encoded'}</Badge>
                </div>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                  <Button type="button" size="sm" variant="default" className="h-8">Product</Button>
                  <Button type="button" size="sm" variant="outline" className="h-8">Combo</Button>
                  <Button type="button" size="sm" variant="outline" className="h-8" onClick={addItem}>
                    <Plus className="mr-2 h-4 w-4" />
                    Add item
                  </Button>
                  <Badge variant="outline">Items: {activeItems.length}</Badge>
                  <Badge variant="outline">Quantity: {totalQuantity}</Badge>
                </div>

                {activeItems.length === 0 && (
                  <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <AlertTriangle className="mt-0.5 h-4 w-4" />
                    <span>This Messenger-captured order still needs product line items before it can be confirmed or encoded.</span>
                  </div>
                )}
                {formErrors.items && <p className="text-sm text-red-600">{formErrors.items}</p>}

                <div className="space-y-2">
                  {data.items.map((item, index) => {
                    const selectedProduct = products.find((product) => String(product.id) === item.product_id);
                    const selectedVariant = selectedProduct?.active_variants.find((variant) => String(variant.id) === item.variant_id);
                    const quantity = Math.max(1, Number(item.quantity || 1));
                    const lineTotal = quantity * numeric(item.unit_price);

                    return (
                    <div key={index} className="grid gap-3 rounded-md bg-slate-50 p-3 md:grid-cols-[72px_1fr_110px_44px_110px_44px] md:items-center">
                      <div className="flex h-12 w-12 items-center justify-center rounded-md border bg-white">
                        <Package className="h-5 w-5 text-muted-foreground" />
                      </div>
                      <div className="grid min-w-0 gap-2">
                        <select
                          value={item.product_id}
                          onChange={(event) => chooseProduct(index, event.target.value)}
                          className="h-9 rounded-md border border-input bg-white px-2 text-sm"
                        >
                          <option value="">Select product...</option>
                          {products.map((product) => (
                            <option key={product.id} value={product.id}>
                              {product.sku} - {product.name}
                            </option>
                          ))}
                        </select>
                        {!selectedProduct && (
                          <Input
                            value={item.product_name}
                            onChange={(event) => updateItem(index, 'product_name', event.target.value)}
                            placeholder="Manual product name, e.g. Avocafe 1 set"
                            className="h-9 bg-white"
                          />
                        )}
                        {selectedProduct && selectedProduct.active_variants.length > 0 && (
                          <select
                            value={item.variant_id}
                            onChange={(event) => chooseVariant(index, event.target.value)}
                            className="h-9 rounded-md border border-input bg-white px-2 text-sm"
                          >
                            <option value="">Default variant</option>
                            {selectedProduct.active_variants.map((variant) => (
                              <option key={variant.id} value={variant.id}>
                                {variant.sku} - {variant.variant_name}
                              </option>
                            ))}
                          </select>
                        )}
                        <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                          {selectedProduct && <span>Stock: {selectedVariant?.available_stock ?? selectedProduct.available_stock ?? 'N/A'}</span>}
                          {selectedProduct?.is_low_stock || selectedVariant?.is_low_stock ? <span className="text-amber-700">Low stock</span> : null}
                        </div>
                      </div>
                      <Input
                        type="number"
                        min="0"
                        step="0.01"
                        value={item.unit_price}
                        onChange={(event) => updateItem(index, 'unit_price', event.target.value)}
                        className="h-9 bg-white text-right"
                      />
                      <span className="text-center text-xs text-muted-foreground">x</span>
                      <Input
                        type="number"
                        min="1"
                        value={item.quantity}
                        onChange={(event) => updateItem(index, 'quantity', event.target.value)}
                        className="h-9 bg-white text-right"
                      />
                      <Button type="button" size="icon" variant="ghost" className="h-9 w-9" onClick={() => removeItem(index)}>
                        <Trash2 className="h-4 w-4" />
                      </Button>
                      <div className="md:col-span-6 text-right text-sm font-semibold text-blue-700">{money(lineTotal)}</div>
                    </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>

            <div className="grid gap-3 lg:grid-cols-[330px_1fr]">
              <Card className="rounded-md">
                <CardHeader className="py-3">
                  <CardTitle className="text-sm">Payment</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <div className="grid grid-cols-2 items-center gap-3">
                    <span>Shipping fee</span>
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.shipping_cost}
                      onChange={(event) => setData('shipping_cost', event.target.value)}
                      className="h-8 text-right"
                    />
                    <span>Payment method</span>
                    <select
                      value={data.payment_method}
                      onChange={(event) => setData('payment_method', event.target.value)}
                      className="h-8 rounded-md border border-input bg-background px-2 text-right"
                    >
                      <option value="COD">COD</option>
                      <option value="CASH">Cash</option>
                      <option value="GCASH">GCash</option>
                      <option value="BANK_TRANSFER">Bank transfer</option>
                      <option value="CARD">Card</option>
                    </select>
                    <span>Subtotal</span>
                    <span className="text-right font-medium">{money(itemsTotal)}</span>
                    <span>Surcharge</span>
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.surcharge_amount}
                      onChange={(event) => setData('surcharge_amount', event.target.value)}
                      className="h-8 text-right"
                    />
                    <span>Discount</span>
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.discount_amount}
                      onChange={(event) => setData('discount_amount', event.target.value)}
                      className="h-8 text-right"
                    />
                    <span>After discount</span>
                    <span className="text-right font-medium">{money(orderTotal)}</span>
                    <span>Amount due</span>
                    <span className="text-right font-semibold text-blue-700">{money(amountDue)}</span>
                    <span>Paid</span>
                    <Input
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.paid_amount}
                      onChange={(event) => setData('paid_amount', event.target.value)}
                      className="h-8 text-right"
                    />
                    <span>Missing</span>
                    <span className="text-right font-semibold text-red-600">{money(amountDue)}</span>
                  </div>
                  {errors.shipping_cost && <p className="text-xs text-red-600">{errors.shipping_cost}</p>}
                </CardContent>
              </Card>

              <Card className="rounded-md">
                <CardHeader className="py-3">
                  <CardTitle className="text-sm">Extra note</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="grid grid-cols-3 rounded-md bg-slate-100 p-1 text-xs">
                    <span className="rounded bg-white py-1 text-center font-medium">Internal</span>
                    <span className="py-1 text-center text-muted-foreground">Printing</span>
                    <span className="py-1 text-center text-muted-foreground">Conversation</span>
                  </div>
                  <Textarea
                    value={data.internal_note}
                    onChange={(event) => setData('internal_note', event.target.value)}
                    placeholder="Add a note or type / for shortcuts"
                    className="min-h-24 bg-slate-50"
                  />
                  <Textarea
                    value={data.notes}
                    onChange={(event) => setData('notes', event.target.value)}
                    placeholder="Order note"
                    className="min-h-20"
                  />
                </CardContent>
              </Card>
            </div>
          </div>

          <div className="space-y-3">
            <Card className="rounded-md">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 py-3">
                <CardTitle className="text-sm">Information</CardTitle>
                <CalendarDays className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="space-y-3 text-xs">
                <div className="grid grid-cols-[130px_1fr] items-center gap-2">
                  <span>Created At</span>
                  <Input disabled value={new Date(order.created_at).toLocaleString()} className="h-8 bg-slate-50" />
                  <span>Customer care staff</span>
                  <Input disabled value={order.agent?.name ?? 'Unassigned'} className="h-8 bg-slate-50" />
                  <span>Status</span>
                  <select
                    value={data.status}
                    onChange={(event) => setData('status', event.target.value)}
                    className="h-8 rounded-md border border-input bg-background px-2"
                  >
                    {statuses.map((status) => (
                      <option key={status.value} value={status.value}>{status.label}</option>
                    ))}
                  </select>
                </div>
              </CardContent>
            </Card>

            <Card className="rounded-md">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 py-3">
                <CardTitle className="text-sm">Customer</CardTitle>
                <UserRound className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="grid grid-cols-2 gap-2">
                  <Input value={data.receiver_name} onChange={(event) => setData('receiver_name', event.target.value)} className="h-8" />
                  <Input value={data.receiver_phone} onChange={(event) => setData('receiver_phone', event.target.value)} className="h-8" />
                </div>
                <div className="rounded-md border border-blue-200 bg-blue-50 p-3">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100">
                      <UserRound className="h-5 w-5 text-blue-700" />
                    </div>
                    <div>
                      <p className="text-sm font-medium">{order.customer?.name ?? order.receiver_name}</p>
                      <p className="text-xs text-blue-700">{order.customer?.normalized_phone ?? order.receiver_phone}</p>
                    </div>
                  </div>
                  <div className="mt-3 grid grid-cols-3 gap-2 border-t border-blue-200 pt-3 text-xs">
                    <span>Total: {money(order.customer?.total_revenue ?? 0)}</span>
                    <span>Success: {order.customer?.successful_orders ?? 0}/{order.customer?.total_orders ?? 1} order(s)</span>
                    <span>Risk: {order.customer?.risk_level ?? 'LOW'}</span>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="rounded-md">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 py-3">
                <CardTitle className="text-sm">Delivery</CardTitle>
                <Truck className="h-4 w-4 text-muted-foreground" />
              </CardHeader>
              <CardContent className="space-y-2">
                <div className="grid grid-cols-2 gap-2">
                  <Input value={data.receiver_name} onChange={(event) => setData('receiver_name', event.target.value)} className="h-8" />
                  <Input value={data.receiver_phone} onChange={(event) => setData('receiver_phone', event.target.value)} className="h-8" />
                </div>
                <Textarea value={data.receiver_address} onChange={(event) => setData('receiver_address', event.target.value)} className="min-h-16" />
                <div className="grid grid-cols-2 gap-2">
                  <Input value={data.state} onChange={(event) => setData('state', event.target.value)} placeholder="Province" className="h-8" />
                  <Input value={data.postal_code} onChange={(event) => setData('postal_code', event.target.value)} placeholder="Postcode" className="h-8" />
                  <Input value={data.city} onChange={(event) => setData('city', event.target.value)} placeholder="City" className="h-8" />
                  <Input value={data.barangay} onChange={(event) => setData('barangay', event.target.value)} placeholder="Barangay" className="h-8" />
                </div>
                <p className="text-xs text-muted-foreground">Address confidence: {Number(order.address_confidence ?? 0)}%</p>
              </CardContent>
            </Card>

            <Card className="rounded-md">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 py-3">
                <CardTitle className="text-sm">Transport</CardTitle>
                <select
                  value={data.courier_code}
                  onChange={(event) => setData('courier_code', event.target.value)}
                  className="h-8 rounded-md border border-input bg-background px-2 text-xs"
                >
                  {couriers.map((courier) => (
                    <option key={courier.value} value={courier.value}>{courier.label}</option>
                  ))}
                </select>
              </CardHeader>
              <CardContent className="space-y-2">
                <div className="grid grid-cols-4 items-center gap-2 text-xs">
                  <span>Size</span>
                  <Input disabled value="0" className="h-8 text-right" />
                  <Input disabled value="0" className="h-8 text-right" />
                  <Input disabled value="0" className="h-8 text-right" />
                </div>
                <Input disabled value="Tracking number will be assigned after courier booking/export" className="h-8 bg-slate-50 text-xs" />
              </CardContent>
            </Card>

            {order.remarks.length > 0 && (
              <Card className="rounded-md">
                <CardHeader className="py-3">
                  <CardTitle className="text-sm">Recent Notes</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {order.remarks.map((remark) => (
                    <div key={remark.id} className="rounded-md border p-2 text-xs">
                      <div className="flex items-center gap-2 text-muted-foreground">
                        <MessageSquare className="h-3 w-3" />
                        {remark.user?.name ?? 'System'} - {new Date(remark.created_at).toLocaleString()}
                      </div>
                      <p className="mt-1">{remark.body}</p>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}
          </div>
        </div>

        <div className="fixed inset-x-0 bottom-0 z-30 border-t bg-white px-4 py-2">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
            <div className="text-sm">
              <p>Amount due: <span className="font-semibold">{money(amountDue)}</span></p>
              <p className="text-red-600">COD: {money(amountDue)}</p>
            </div>
            <div className="flex flex-wrap justify-end gap-2">
              <Badge className="flex h-9 items-center rounded-md bg-cyan-700 px-3 text-white">Status: {data.status}</Badge>
              <Button type="button" variant="outline">
                <Printer className="mr-2 h-4 w-4" />
                Print
              </Button>
              <Button type="button" variant="outline">
                <Expand className="mr-2 h-4 w-4" />
                Full screen
              </Button>
              <Button type="submit" disabled={processing}>
                <Save className="mr-2 h-4 w-4" />
                Save
              </Button>
            </div>
          </div>
        </div>
      </form>
    </AppLayout>
  );
}
