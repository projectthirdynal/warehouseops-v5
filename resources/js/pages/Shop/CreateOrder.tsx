import { FormEvent, useMemo } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  BadgePercent,
  Calculator,
  CreditCard,
  MapPinned,
  PackagePlus,
  Plus,
  Save,
  Search,
  Store,
  Trash2,
  User,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

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

interface Courier {
  value: string;
  label: string;
}

interface CustomerOption {
  id: number;
  name: string;
  phone: string | null;
  normalized_phone: string | null;
  canonical_address: string | null;
  landmark: string | null;
  barangay: string | null;
  city_municipality: string | null;
  province: string | null;
  risk_level: string | null;
  is_blacklisted: boolean;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  success_rate: string | number | null;
  last_order_date: string | null;
}

interface FacebookPageOption {
  id: number;
  page_name: string;
  page_id: string;
  webhook_status: string;
}

interface DuplicateWarning {
  id: number;
  order_number: string;
  status: string;
  total_amount: string | number;
  created_at: string;
  product?: { id: number; name: string; sku: string } | null;
}

interface CartItemForm {
  product_id: string;
  variant_id: string;
  product_name: string;
  quantity: string;
  unit_price: string;
}

interface OrderForm {
  customer_name: string;
  phone: string;
  complete_address: string;
  landmark: string;
  barangay: string;
  city_municipality: string;
  province: string;
  items: CartItemForm[];
  shipping_fee: string;
  discount_amount: string;
  surcharge_amount: string;
  paid_amount: string;
  payment_method: string;
  initial_status: string;
  sales_channel: string;
  facebook_page_id: string;
  courier_code: string;
  remarks: string;
  conversation_id: string;
}

interface Props {
  products: Product[];
  customers: CustomerOption[];
  facebook_pages: FacebookPageOption[];
  couriers: Courier[];
  payment_methods: Courier[];
  prefill?: Partial<OrderForm> | null;
  duplicate_warnings: DuplicateWarning[];
}

function money(value: number) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(value);
}

function numeric(value: string | number | null | undefined) {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function createEmptyItem(): CartItemForm {
  return {
    product_id: '',
    variant_id: '',
    product_name: '',
    quantity: '1',
    unit_price: '',
  };
}

export default function CreateShopOrder({
  products,
  customers,
  facebook_pages,
  couriers,
  payment_methods,
  prefill,
  duplicate_warnings,
}: Props) {
  const { data, setData, post, processing, errors } = useForm<OrderForm>({
    customer_name: prefill?.customer_name ?? '',
    phone: prefill?.phone ?? '',
    complete_address: prefill?.complete_address ?? '',
    landmark: '',
    barangay: '',
    city_municipality: '',
    province: '',
    items: prefill?.items && prefill.items.length > 0 ? prefill.items : [createEmptyItem()],
    shipping_fee: '0',
    discount_amount: '0',
    surcharge_amount: '0',
    paid_amount: '0',
    payment_method: 'COD',
    initial_status: 'CONFIRMED',
    sales_channel: prefill?.conversation_id ? 'facebook_shop' : 'manual_shop',
    facebook_page_id: '',
    courier_code: 'MANUAL',
    remarks: prefill?.remarks ?? '',
    conversation_id: prefill?.conversation_id ? String(prefill.conversation_id) : '',
  });

  const matchingCustomers = useMemo(() => {
    const query = `${data.customer_name} ${data.phone}`.trim().toLowerCase();

    if (!query) {
      return customers.slice(0, 8);
    }

    return customers
      .filter((customer) => (
        customer.name.toLowerCase().includes(query)
        || String(customer.phone ?? '').includes(query)
        || String(customer.normalized_phone ?? '').includes(query)
      ))
      .slice(0, 8);
  }, [customers, data.customer_name, data.phone]);

  const itemError = (index: number, field: string) => {
    const key = `items.${index}.${field}` as keyof typeof errors;
    return errors[key];
  };

  const applyCustomer = (customer: CustomerOption) => {
    setData({
      ...data,
      customer_name: customer.name,
      phone: customer.normalized_phone ?? customer.phone ?? '',
      complete_address: customer.canonical_address ?? '',
      landmark: customer.landmark ?? '',
      barangay: customer.barangay ?? '',
      city_municipality: customer.city_municipality ?? '',
      province: customer.province ?? '',
    });
  };

  const updateItem = (index: number, field: keyof CartItemForm, value: string) => {
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
    setData('items', [...data.items, createEmptyItem()]);
  };

  const removeItem = (index: number) => {
    setData('items', data.items.length === 1 ? [createEmptyItem()] : data.items.filter((_, itemIndex) => itemIndex !== index));
  };

  const subtotal = data.items.reduce((total, item) => (
    total + (item.product_id || item.product_name.trim() ? Math.max(1, Number(item.quantity || 1)) * numeric(item.unit_price) : 0)
  ), 0);
  const totalQuantity = data.items.reduce((total, item) => (
    total + (item.product_id || item.product_name.trim() ? Math.max(1, Number(item.quantity || 1)) : 0)
  ), 0);
  const shippingFee = numeric(data.shipping_fee);
  const discountAmount = numeric(data.discount_amount);
  const surchargeAmount = numeric(data.surcharge_amount);
  const paidAmount = numeric(data.paid_amount);
  const total = Math.max(0, subtotal + shippingFee + surchargeAmount - discountAmount);
  const amountDue = Math.max(0, total - paidAmount);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/shop/orders');
  };

  return (
    <AppLayout>
      <Head title="Create Shop Order" />

      <form onSubmit={submit} className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
              <Link href="/shop">
                <ArrowLeft className="mr-2 h-4 w-4" />
                Shop
              </Link>
            </Button>
            <h1 className="text-3xl font-bold tracking-tight">POS Register</h1>
            <p className="text-muted-foreground">
              {data.conversation_id ? `Converting Shop conversation #${data.conversation_id}` : 'Create walk-in, chat, Facebook, and phone orders from one register'}
            </p>
          </div>
          <Button type="submit" disabled={processing}>
            <Save className="mr-2 h-4 w-4" />
            Save Order
          </Button>
        </div>

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Store className="h-5 w-5" />
                  Register Context
                </CardTitle>
                <CardDescription>Classify where the order came from before fulfillment</CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 md:grid-cols-3">
                <div className="space-y-2">
                  <Label htmlFor="sales_channel">Sales channel</Label>
                  <select
                    id="sales_channel"
                    value={data.sales_channel}
                    onChange={(event) => setData('sales_channel', event.target.value)}
                    disabled={Boolean(data.conversation_id)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
                  >
                    <option value="manual_shop">Manual shop</option>
                    <option value="walk_in">Walk-in</option>
                    <option value="facebook_shop">Facebook shop</option>
                    <option value="phone_order">Phone order</option>
                  </select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="facebook_page_id">Facebook Page</Label>
                  <select
                    id="facebook_page_id"
                    value={data.facebook_page_id}
                    onChange={(event) => setData('facebook_page_id', event.target.value)}
                    disabled={data.sales_channel !== 'facebook_shop' || Boolean(data.conversation_id)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
                  >
                    <option value="">No Page selected</option>
                    {facebook_pages.map((page) => (
                      <option key={page.id} value={page.id}>
                        {page.page_name} ({page.webhook_status})
                      </option>
                    ))}
                  </select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="initial_status">Initial status</Label>
                  <select
                    id="initial_status"
                    value={data.initial_status}
                    onChange={(event) => setData('initial_status', event.target.value)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    <option value="CONFIRMED">Confirmed</option>
                    <option value="PENDING">Pending review</option>
                  </select>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <User className="h-5 w-5" />
                  Customer
                </CardTitle>
                <CardDescription>Phone is normalized for customer matching</CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="customer_name">Customer name</Label>
                  <Input
                    id="customer_name"
                    value={data.customer_name}
                    onChange={(event) => setData('customer_name', event.target.value)}
                    placeholder="Maria Santos"
                  />
                  {errors.customer_name && <p className="text-xs text-destructive">{errors.customer_name}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="phone">Phone number</Label>
                  <Input
                    id="phone"
                    value={data.phone}
                    onChange={(event) => setData('phone', event.target.value)}
                    placeholder="09171234567"
                  />
                  {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
                </div>
                <div className="space-y-3 md:col-span-2">
                  <div className="flex items-center gap-2 text-sm font-medium">
                    <Search className="h-4 w-4" />
                    Customer recall
                  </div>
                  <div className="grid gap-2 md:grid-cols-2">
                    {matchingCustomers.map((customer) => (
                      <button
                        key={customer.id}
                        type="button"
                        onClick={() => applyCustomer(customer)}
                        className="rounded-md border bg-background p-3 text-left transition-colors hover:bg-accent/40"
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <p className="text-sm font-medium">{customer.name}</p>
                            <p className="text-xs text-muted-foreground">{customer.normalized_phone ?? customer.phone ?? 'No phone'}</p>
                          </div>
                          {customer.is_blacklisted ? (
                            <Badge variant="destructive">Blocked</Badge>
                          ) : (
                            <Badge variant="outline">{customer.total_orders} order{customer.total_orders === 1 ? '' : 's'}</Badge>
                          )}
                        </div>
                        <p className="mt-2 line-clamp-1 text-xs text-muted-foreground">
                          {customer.canonical_address ?? 'No saved address'}
                        </p>
                      </button>
                    ))}
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <MapPinned className="h-5 w-5" />
                  Delivery Address
                </CardTitle>
                <CardDescription>Address mapping will use these fields for encoder review</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="complete_address">Complete address</Label>
                  <Textarea
                    id="complete_address"
                    value={data.complete_address}
                    onChange={(event) => setData('complete_address', event.target.value)}
                    placeholder="House number, street, barangay, city, province"
                  />
                  {errors.complete_address && <p className="text-xs text-destructive">{errors.complete_address}</p>}
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="landmark">Landmark</Label>
                    <Input
                      id="landmark"
                      value={data.landmark}
                      onChange={(event) => setData('landmark', event.target.value)}
                      placeholder="Near municipal hall"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="barangay">Barangay</Label>
                    <Input
                      id="barangay"
                      value={data.barangay}
                      onChange={(event) => setData('barangay', event.target.value)}
                      placeholder="San Roque"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="city_municipality">City / Municipality</Label>
                    <Input
                      id="city_municipality"
                      value={data.city_municipality}
                      onChange={(event) => setData('city_municipality', event.target.value)}
                      placeholder="Tarlac City"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="province">Province</Label>
                    <Input
                      id="province"
                      value={data.province}
                      onChange={(event) => setData('province', event.target.value)}
                      placeholder="Tarlac"
                    />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <CardTitle className="flex items-center gap-2">
                      <PackagePlus className="h-5 w-5" />
                      Cart Items
                    </CardTitle>
                    <CardDescription>Build one Shop order with multiple products and variants</CardDescription>
                  </div>
                  <Button type="button" variant="outline" onClick={addItem}>
                    <Plus className="mr-2 h-4 w-4" />
                    Add Item
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                {data.items.map((item, index) => {
                  const selectedProduct = products.find((product) => String(product.id) === item.product_id);
                  const selectedVariant = selectedProduct?.active_variants.find((variant) => String(variant.id) === item.variant_id);
                  const quantity = Math.max(1, Number(item.quantity || 1));
                  const lineTotal = quantity * numeric(item.unit_price);
                  const selectedStock = selectedVariant?.available_stock ?? selectedProduct?.available_stock ?? null;
                  const isLowStock = selectedVariant?.is_low_stock ?? selectedProduct?.is_low_stock ?? false;

                  return (
                    <div key={index} className="rounded-lg border p-4">
                      <div className="mb-4 flex items-center justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">Item {index + 1}</p>
                          <p className="text-xs text-muted-foreground">
                            {selectedVariant?.variant_name ?? selectedProduct?.name ?? 'Select a product'}
                          </p>
                        </div>
                        {selectedProduct && (
                          <Badge variant={isLowStock ? 'destructive' : 'outline'}>
                            {selectedStock === null ? 'No stock record' : `${selectedStock} available`}
                          </Badge>
                        )}
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          onClick={() => removeItem(index)}
                          disabled={data.items.length === 1}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>

                      <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2 md:col-span-2">
                          <Label htmlFor={`product_id_${index}`}>Product</Label>
                          <select
                            id={`product_id_${index}`}
                            value={item.product_id}
                            onChange={(event) => chooseProduct(index, event.target.value)}
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                          >
                            <option value="">Select product</option>
                            {products.map((product) => (
                              <option key={product.id} value={product.id}>
                                {product.name} ({product.sku}) {product.available_stock === null ? '' : `- ${product.available_stock} available`}
                              </option>
                            ))}
                          </select>
                          {itemError(index, 'product_id') && <p className="text-xs text-destructive">{itemError(index, 'product_id')}</p>}
                        </div>

                        {!selectedProduct && (
                          <div className="space-y-2 md:col-span-2">
                            <Label htmlFor={`product_name_${index}`}>Manual product name</Label>
                            <Input
                              id={`product_name_${index}`}
                              value={item.product_name}
                              onChange={(event) => updateItem(index, 'product_name', event.target.value)}
                              placeholder="Example: Avocafe 1 set"
                            />
                            {itemError(index, 'product_name') && <p className="text-xs text-destructive">{itemError(index, 'product_name')}</p>}
                          </div>
                        )}

                        <div className="space-y-2">
                          <Label htmlFor={`variant_id_${index}`}>Variant</Label>
                          <select
                            id={`variant_id_${index}`}
                            value={item.variant_id}
                            onChange={(event) => chooseVariant(index, event.target.value)}
                            disabled={!selectedProduct || selectedProduct.active_variants.length === 0}
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
                          >
                            <option value="">Default</option>
                            {selectedProduct?.active_variants.map((variant) => (
                              <option key={variant.id} value={variant.id}>
                                {variant.variant_name} ({variant.sku}) {variant.available_stock === null ? '' : `- ${variant.available_stock} available`}
                              </option>
                            ))}
                          </select>
                          {itemError(index, 'variant_id') && <p className="text-xs text-destructive">{itemError(index, 'variant_id')}</p>}
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor={`quantity_${index}`}>Quantity</Label>
                          <Input
                            id={`quantity_${index}`}
                            type="number"
                            min="1"
                            value={item.quantity}
                            onChange={(event) => updateItem(index, 'quantity', event.target.value)}
                          />
                          {itemError(index, 'quantity') && <p className="text-xs text-destructive">{itemError(index, 'quantity')}</p>}
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor={`unit_price_${index}`}>Unit price</Label>
                          <Input
                            id={`unit_price_${index}`}
                            type="number"
                            min="0"
                            step="0.01"
                            value={item.unit_price}
                            onChange={(event) => updateItem(index, 'unit_price', event.target.value)}
                          />
                          {itemError(index, 'unit_price') && <p className="text-xs text-destructive">{itemError(index, 'unit_price')}</p>}
                        </div>

                        <div className="flex items-end">
                          <div className="w-full rounded-lg bg-muted px-3 py-2 text-sm">
                            <div className="flex justify-between">
                              <span className="text-muted-foreground">Line total</span>
                              <span className="font-medium">{money(lineTotal)}</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </CardContent>
            </Card>
          </div>

          <div className="space-y-6">
            {duplicate_warnings.length > 0 && (
              <Card className="border-amber-200 bg-amber-50/50">
                <CardHeader>
                  <CardTitle className="flex items-center gap-2 text-amber-900">
                    <AlertTriangle className="h-5 w-5" />
                    Possible Duplicates
                  </CardTitle>
                  <CardDescription>Recent Shop orders found for this phone number</CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  {duplicate_warnings.map((order) => (
                    <Link key={order.id} href={`/orders/${order.id}`} className="block rounded-lg border bg-background p-3 text-sm transition-colors hover:bg-accent/30">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium">{order.order_number}</p>
                          <p className="text-xs text-muted-foreground">{order.product?.name ?? 'No product'}</p>
                        </div>
                        <Badge variant="outline">{order.status}</Badge>
                      </div>
                      <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                        <span>{new Date(order.created_at).toLocaleString()}</span>
                        <span>{money(Number(order.total_amount ?? 0))}</span>
                      </div>
                    </Link>
                  ))}
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Calculator className="h-5 w-5" />
                  Order Summary
                </CardTitle>
                <CardDescription>COD amount preview for the full cart</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  {data.items.map((item, index) => {
                    const selectedProduct = products.find((product) => String(product.id) === item.product_id);
                    const selectedVariant = selectedProduct?.active_variants.find((variant) => String(variant.id) === item.variant_id);
                    const quantity = Math.max(1, Number(item.quantity || 1));
                    const lineTotal = quantity * numeric(item.unit_price);

                    return (
                      <div key={index} className="rounded-lg border p-3">
                        <div className="flex justify-between gap-3 text-sm">
                          <div>
                            <p className="font-medium">{(selectedVariant?.variant_name ?? selectedProduct?.name ?? item.product_name.trim()) || `Item ${index + 1}`}</p>
                            <p className="text-xs text-muted-foreground">
                              {selectedVariant?.sku ?? selectedProduct?.sku ?? 'No SKU'} x {quantity}
                            </p>
                          </div>
                          <span className="font-medium">{money(lineTotal)}</span>
                        </div>
                      </div>
                    );
                  })}
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Total items</span>
                    <span>{totalQuantity}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Subtotal</span>
                    <span>{money(subtotal)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Shipping</span>
                    <span>{money(shippingFee)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Surcharge</span>
                    <span>{money(surchargeAmount)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Discount</span>
                    <span>-{money(discountAmount)}</span>
                  </div>
                  <div className="flex justify-between border-t pt-3 text-base font-semibold">
                    <span>Order total</span>
                    <span>{money(total)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Paid</span>
                    <span>{money(paidAmount)}</span>
                  </div>
                  <div className="flex justify-between text-base font-semibold text-primary">
                    <span>Amount due / COD</span>
                    <span>{money(amountDue)}</span>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <CreditCard className="h-5 w-5" />
                  Payment
                </CardTitle>
                <CardDescription>Supports COD, paid counter sales, and partial payments</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="payment_method">Payment method</Label>
                  <select
                    id="payment_method"
                    value={data.payment_method}
                    onChange={(event) => setData('payment_method', event.target.value)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    {payment_methods.map((method) => (
                      <option key={method.value} value={method.value}>
                        {method.label}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="paid_amount">Paid amount</Label>
                    <Input
                      id="paid_amount"
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.paid_amount}
                      onChange={(event) => setData('paid_amount', event.target.value)}
                    />
                    {errors.paid_amount && <p className="text-xs text-destructive">{errors.paid_amount}</p>}
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="surcharge_amount">Surcharge</Label>
                    <Input
                      id="surcharge_amount"
                      type="number"
                      min="0"
                      step="0.01"
                      value={data.surcharge_amount}
                      onChange={(event) => setData('surcharge_amount', event.target.value)}
                    />
                    {errors.surcharge_amount && <p className="text-xs text-destructive">{errors.surcharge_amount}</p>}
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="shipping_fee">Shipping fee</Label>
                  <Input
                    id="shipping_fee"
                    type="number"
                    min="0"
                    step="0.01"
                    value={data.shipping_fee}
                    onChange={(event) => setData('shipping_fee', event.target.value)}
                  />
                  {errors.shipping_fee && <p className="text-xs text-destructive">{errors.shipping_fee}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="discount_amount" className="flex items-center gap-2">
                    <BadgePercent className="h-4 w-4" />
                    Discount
                  </Label>
                  <Input
                    id="discount_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    value={data.discount_amount}
                    onChange={(event) => setData('discount_amount', event.target.value)}
                  />
                  {errors.discount_amount && <p className="text-xs text-destructive">{errors.discount_amount}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="courier_code">Courier</Label>
                  <select
                    id="courier_code"
                    value={data.courier_code}
                    onChange={(event) => setData('courier_code', event.target.value)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    {couriers.map((courier) => (
                      <option key={courier.value} value={courier.value}>
                        {courier.label}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="remarks">Remarks</Label>
                  <Textarea
                    id="remarks"
                    value={data.remarks}
                    onChange={(event) => setData('remarks', event.target.value)}
                    placeholder="Customer notes, product promise, delivery instruction"
                  />
                  {errors.remarks && <p className="text-xs text-destructive">{errors.remarks}</p>}
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </form>
    </AppLayout>
  );
}
