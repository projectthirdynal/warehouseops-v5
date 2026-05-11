import { FormEvent, useMemo } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Calculator, MapPinned, PackagePlus, Phone, Save, User } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
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
}

interface Product {
  id: number;
  sku: string;
  name: string;
  selling_price: string | number;
  active_variants: ProductVariant[];
}

interface Courier {
  value: string;
  label: string;
}

interface Props {
  products: Product[];
  couriers: Courier[];
}

interface OrderForm {
  customer_name: string;
  phone: string;
  complete_address: string;
  landmark: string;
  barangay: string;
  city_municipality: string;
  province: string;
  product_id: string;
  variant_id: string;
  quantity: string;
  unit_price: string;
  shipping_fee: string;
  courier_code: string;
  remarks: string;
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

export default function CreateShopOrder({ products, couriers }: Props) {
  const { data, setData, post, processing, errors } = useForm<OrderForm>({
    customer_name: '',
    phone: '',
    complete_address: '',
    landmark: '',
    barangay: '',
    city_municipality: '',
    province: '',
    product_id: '',
    variant_id: '',
    quantity: '1',
    unit_price: '',
    shipping_fee: '0',
    courier_code: 'MANUAL',
    remarks: '',
  });

  const selectedProduct = useMemo(
    () => products.find((product) => String(product.id) === data.product_id),
    [data.product_id, products]
  );

  const selectedVariant = useMemo(
    () => selectedProduct?.active_variants.find((variant) => String(variant.id) === data.variant_id),
    [data.variant_id, selectedProduct]
  );

  const quantity = Math.max(1, Number(data.quantity || 1));
  const unitPrice = numeric(data.unit_price);
  const shippingFee = numeric(data.shipping_fee);
  const subtotal = quantity * unitPrice;
  const total = subtotal + shippingFee;

  const chooseProduct = (productId: string) => {
    const product = products.find((item) => String(item.id) === productId);

    setData((current) => ({
      ...current,
      product_id: productId,
      variant_id: '',
      unit_price: product ? String(product.selling_price) : '',
    }));
  };

  const chooseVariant = (variantId: string) => {
    const variant = selectedProduct?.active_variants.find((item) => String(item.id) === variantId);

    setData((current) => ({
      ...current,
      variant_id: variantId,
      unit_price: variant?.selling_price ? String(variant.selling_price) : current.unit_price,
    }));
  };

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
            <h1 className="text-3xl font-bold tracking-tight">Create Shop Order</h1>
            <p className="text-muted-foreground">Manual POS entry for Facebook, chat, and phone orders</p>
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
                <CardTitle className="flex items-center gap-2">
                  <PackagePlus className="h-5 w-5" />
                  Product
                </CardTitle>
                <CardDescription>Select one product for this first POS order version</CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2 md:col-span-2">
                  <Label htmlFor="product_id">Product</Label>
                  <select
                    id="product_id"
                    value={data.product_id}
                    onChange={(event) => chooseProduct(event.target.value)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    <option value="">Select product</option>
                    {products.map((product) => (
                      <option key={product.id} value={product.id}>
                        {product.name} ({product.sku})
                      </option>
                    ))}
                  </select>
                  {errors.product_id && <p className="text-xs text-destructive">{errors.product_id}</p>}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="variant_id">Variant</Label>
                  <select
                    id="variant_id"
                    value={data.variant_id}
                    onChange={(event) => chooseVariant(event.target.value)}
                    disabled={!selectedProduct || selectedProduct.active_variants.length === 0}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
                  >
                    <option value="">Default</option>
                    {selectedProduct?.active_variants.map((variant) => (
                      <option key={variant.id} value={variant.id}>
                        {variant.variant_name} ({variant.sku})
                      </option>
                    ))}
                  </select>
                  {errors.variant_id && <p className="text-xs text-destructive">{errors.variant_id}</p>}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="quantity">Quantity</Label>
                  <Input
                    id="quantity"
                    type="number"
                    min="1"
                    value={data.quantity}
                    onChange={(event) => setData('quantity', event.target.value)}
                  />
                  {errors.quantity && <p className="text-xs text-destructive">{errors.quantity}</p>}
                </div>

                <div className="space-y-2">
                  <Label htmlFor="unit_price">Unit price</Label>
                  <Input
                    id="unit_price"
                    type="number"
                    min="0"
                    step="0.01"
                    value={data.unit_price}
                    onChange={(event) => setData('unit_price', event.target.value)}
                  />
                  {errors.unit_price && <p className="text-xs text-destructive">{errors.unit_price}</p>}
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
              </CardContent>
            </Card>
          </div>

          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Calculator className="h-5 w-5" />
                  Order Summary
                </CardTitle>
                <CardDescription>COD amount preview</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="rounded-lg border p-3">
                  <p className="text-sm font-medium">{selectedVariant?.variant_name ?? selectedProduct?.name ?? 'No product selected'}</p>
                  <p className="text-xs text-muted-foreground">
                    {selectedVariant?.sku ?? selectedProduct?.sku ?? 'Select an item to continue'}
                  </p>
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Subtotal</span>
                    <span>{money(subtotal)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Shipping</span>
                    <span>{money(shippingFee)}</span>
                  </div>
                  <div className="flex justify-between border-t pt-3 text-base font-semibold">
                    <span>Total COD</span>
                    <span>{money(total)}</span>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Phone className="h-5 w-5" />
                  Processing
                </CardTitle>
                <CardDescription>Initial order status is Confirmed</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
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
