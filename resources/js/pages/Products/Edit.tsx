import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Save } from 'lucide-react';
import type { Product } from '@/types';

interface Props {
  product: Product;
  categories: string[];
  brands: string[];
}

interface ProductForm {
  sku: string;
  name: string;
  brand: string;
  category: string;
  selling_price: string;
  cost_price: string;
  weight_grams: string;
  barcode: string;
  qr_code: string;
  min_stock_level: string;
  max_stock_level: string;
  expiry_tracking: boolean;
  description: string;
  is_active: boolean;
  requires_qa: boolean;
}

const inputClass = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/20';
const labelClass = 'block text-sm font-medium mb-1';

function value(value: string | number | boolean | null | undefined): string {
  return value === null || value === undefined ? '' : String(value);
}

export default function ProductEdit({ product, categories, brands }: Props) {
  const [form, setForm] = useState<ProductForm>({
    sku: value(product.sku),
    name: value(product.name),
    brand: value(product.brand),
    category: value(product.category),
    selling_price: value(product.selling_price),
    cost_price: value(product.cost_price),
    weight_grams: value(product.weight_grams),
    barcode: value(product.barcode),
    qr_code: value(product.qr_code),
    min_stock_level: value(product.min_stock_level ?? 0),
    max_stock_level: value(product.max_stock_level),
    expiry_tracking: Boolean(product.expiry_tracking),
    description: value(product.description),
    is_active: Boolean(product.is_active),
    requires_qa: Boolean(product.requires_qa),
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const updateField = (field: keyof ProductForm, nextValue: string | boolean) => {
    setForm((previous) => ({ ...previous, [field]: nextValue }));
  };

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});

    router.put(`/products/${product.id}`, {
      ...form,
      selling_price: Number(form.selling_price || 0),
      cost_price: Number(form.cost_price || 0),
      weight_grams: Number.parseInt(form.weight_grams || '0', 10),
      min_stock_level: Number.parseInt(form.min_stock_level || '0', 10),
      max_stock_level: form.max_stock_level === '' ? null : Number.parseInt(form.max_stock_level, 10),
    }, {
      preserveScroll: true,
      onError: (validationErrors) => setErrors(validationErrors),
      onFinish: () => setSubmitting(false),
    });
  };

  return (
    <AppLayout>
      <Head title={`Edit ${product.name}`} />

      <div className="mx-auto max-w-3xl space-y-6">
        <div className="flex items-center gap-3">
          <Link href={`/products/${product.id}`}>
            <Button variant="ghost" size="icon"><ArrowLeft className="h-4 w-4" /></Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold">Edit Product</h1>
            <p className="text-sm text-muted-foreground">{product.sku}</p>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-6">
          <Card>
            <CardHeader><CardTitle className="text-base">Product Information</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-4 md:grid-cols-2">
                <Field label="SKU *" error={errors.sku}>
                  <input className={inputClass} value={form.sku} onChange={(event) => updateField('sku', event.target.value)} />
                </Field>
                <Field label="Product Name *" error={errors.name}>
                  <input className={inputClass} value={form.name} onChange={(event) => updateField('name', event.target.value)} />
                </Field>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Brand" error={errors.brand}>
                  <input className={inputClass} list="brands" value={form.brand} onChange={(event) => updateField('brand', event.target.value)} />
                  <datalist id="brands">{brands.map((brand) => <option key={brand} value={brand} />)}</datalist>
                </Field>
                <Field label="Category" error={errors.category}>
                  <input className={inputClass} list="categories" value={form.category} onChange={(event) => updateField('category', event.target.value)} />
                  <datalist id="categories">{categories.map((category) => <option key={category} value={category} />)}</datalist>
                </Field>
              </div>

              <Field label="Description" error={errors.description}>
                <textarea className={inputClass} rows={3} value={form.description} onChange={(event) => updateField('description', event.target.value)} />
              </Field>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">Pricing, Codes, and Inventory Rules</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-4 md:grid-cols-3">
                <Field label="Selling Price *" error={errors.selling_price}>
                  <input type="number" min="0" step="0.01" className={inputClass} value={form.selling_price} onChange={(event) => updateField('selling_price', event.target.value)} />
                </Field>
                <Field label="Cost Price *" error={errors.cost_price}>
                  <input type="number" min="0" step="0.01" className={inputClass} value={form.cost_price} onChange={(event) => updateField('cost_price', event.target.value)} />
                </Field>
                <Field label="Weight (grams) *" error={errors.weight_grams}>
                  <input type="number" min="0" className={inputClass} value={form.weight_grams} onChange={(event) => updateField('weight_grams', event.target.value)} />
                </Field>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Barcode" error={errors.barcode}>
                  <input className={inputClass} value={form.barcode} onChange={(event) => updateField('barcode', event.target.value)} />
                </Field>
                <Field label="QR Code" error={errors.qr_code}>
                  <input className={inputClass} value={form.qr_code} onChange={(event) => updateField('qr_code', event.target.value)} />
                </Field>
              </div>

              <div className="grid gap-4 md:grid-cols-2">
                <Field label="Minimum Stock Level" error={errors.min_stock_level}>
                  <input type="number" min="0" className={inputClass} value={form.min_stock_level} onChange={(event) => updateField('min_stock_level', event.target.value)} />
                </Field>
                <Field label="Maximum Stock Level" error={errors.max_stock_level}>
                  <input type="number" min="0" className={inputClass} value={form.max_stock_level} onChange={(event) => updateField('max_stock_level', event.target.value)} />
                </Field>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle className="text-base">Settings</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              <label className="flex cursor-pointer items-center gap-3">
                <input type="checkbox" checked={form.is_active} onChange={(event) => updateField('is_active', event.target.checked)} className="rounded" />
                <span className="text-sm">Active and available for ordering</span>
              </label>
              <label className="flex cursor-pointer items-center gap-3">
                <input type="checkbox" checked={form.requires_qa} onChange={(event) => updateField('requires_qa', event.target.checked)} className="rounded" />
                <span className="text-sm">Requires QA review before dispatch</span>
              </label>
              <label className="flex cursor-pointer items-center gap-3">
                <input type="checkbox" checked={form.expiry_tracking} onChange={(event) => updateField('expiry_tracking', event.target.checked)} className="rounded" />
                <span className="text-sm">Track expiry dates for this product</span>
              </label>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Link href={`/products/${product.id}`}>
              <Button type="button" variant="outline">Cancel</Button>
            </Link>
            <Button type="submit" disabled={submitting}>
              <Save className="mr-2 h-4 w-4" />
              {submitting ? 'Saving...' : 'Save Product'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <div>
      <label className={labelClass}>{label}</label>
      {children}
      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}

