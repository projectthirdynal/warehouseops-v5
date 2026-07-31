import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft } from 'lucide-react';
import { Link } from '@inertiajs/react';

interface Variant {
  id: number;
  variant_name: string;
  sku: string;
  selling_price: number | null;
  cost_price: number | null;
  weight_grams: number | null;
}

interface Product {
  id: number;
  sku: string;
  name: string;
  brand: string | null;
  category: string | null;
  selling_price: number;
  cost_price: number;
  weight_grams: number;
  description: string | null;
  is_active: boolean;
  requires_qa: boolean;
  reorder_point: number;
  variants: Variant[];
}

interface Props {
  product: Product;
  categories: string[];
  brands: string[];
}

export default function ProductEdit({ product, categories, brands }: Props) {
  const [form, setForm] = useState({
    sku: product.sku,
    name: product.name,
    brand: product.brand ?? '',
    category: product.category ?? '',
    selling_price: String(product.selling_price),
    cost_price: String(product.cost_price),
    weight_grams: String(product.weight_grams),
    description: product.description ?? '',
    is_active: product.is_active,
    requires_qa: product.requires_qa,
    reorder_point: String(product.reorder_point ?? 10),
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const updateField = (field: string, value: string | boolean) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setErrors({});

    const payload: Record<string, string | number | boolean> = {
      ...form,
      selling_price: parseFloat(form.selling_price) || 0,
      cost_price: parseFloat(form.cost_price) || 0,
      weight_grams: parseInt(form.weight_grams) || 0,
      reorder_point: parseInt(form.reorder_point) || 10,
    };

    router.put(`/products/${product.id}`, payload as Record<string, string>, {
      onError: (errs) => {
        setErrors(errs);
        setSubmitting(false);
      },
      onFinish: () => setSubmitting(false),
    });
  };

  const inputClass =
    'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/20';
  const labelClass = 'block text-sm font-medium mb-1';

  return (
    <AppLayout>
      <div className="max-w-3xl mx-auto space-y-4">
        <div className="flex items-center gap-3">
          <Link href={`/products/${product.id}`}>
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-xl font-bold font-display">Edit Product</h1>
            <p className="text-sm text-muted-foreground">{product.name}</p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Product Information</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className={labelClass}>SKU *</label>
                  <input
                    className={inputClass}
                    value={form.sku}
                    onChange={(e) => updateField('sku', e.target.value)}
                  />
                  {errors.sku && <p className="text-xs text-destructive mt-1">{errors.sku}</p>}
                </div>
                <div>
                  <label className={labelClass}>Product Name *</label>
                  <input
                    className={inputClass}
                    value={form.name}
                    onChange={(e) => updateField('name', e.target.value)}
                  />
                  {errors.name && <p className="text-xs text-destructive mt-1">{errors.name}</p>}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className={labelClass}>Brand</label>
                  <input
                    className={inputClass}
                    list="brands"
                    value={form.brand}
                    onChange={(e) => updateField('brand', e.target.value)}
                  />
                  <datalist id="brands">
                    {brands.map((b) => (
                      <option key={b} value={b} />
                    ))}
                  </datalist>
                </div>
                <div>
                  <label className={labelClass}>Category</label>
                  <input
                    className={inputClass}
                    list="categories"
                    value={form.category}
                    onChange={(e) => updateField('category', e.target.value)}
                  />
                  <datalist id="categories">
                    {categories.map((c) => (
                      <option key={c} value={c} />
                    ))}
                  </datalist>
                </div>
              </div>

              <div>
                <label className={labelClass}>Description</label>
                <textarea
                  className={inputClass}
                  rows={3}
                  value={form.description}
                  onChange={(e) => updateField('description', e.target.value)}
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Pricing & Weight</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className={labelClass}>Selling Price (PHP) *</label>
                  <input
                    type="number"
                    step="0.01"
                    className={inputClass}
                    value={form.selling_price}
                    onChange={(e) => updateField('selling_price', e.target.value)}
                  />
                  {errors.selling_price && (
                    <p className="text-xs text-destructive mt-1">{errors.selling_price}</p>
                  )}
                </div>
                <div>
                  <label className={labelClass}>Cost Price (PHP) *</label>
                  <input
                    type="number"
                    step="0.01"
                    className={inputClass}
                    value={form.cost_price}
                    onChange={(e) => updateField('cost_price', e.target.value)}
                  />
                  {errors.cost_price && (
                    <p className="text-xs text-destructive mt-1">{errors.cost_price}</p>
                  )}
                </div>
                <div>
                  <label className={labelClass}>Weight (grams) *</label>
                  <input
                    type="number"
                    className={inputClass}
                    value={form.weight_grams}
                    onChange={(e) => updateField('weight_grams', e.target.value)}
                  />
                </div>
              </div>
              {form.selling_price && form.cost_price && parseFloat(form.selling_price) > 0 && (
                <p className="text-xs text-muted-foreground">
                  Margin:{' '}
                  {(
                    ((parseFloat(form.selling_price) - parseFloat(form.cost_price)) /
                      parseFloat(form.selling_price)) *
                    100
                  ).toFixed(1)}
                  %
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Settings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <label className="flex items-center gap-3 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.is_active}
                  onChange={(e) => updateField('is_active', e.target.checked)}
                  className="rounded"
                />
                <span className="text-sm">Active — available for ordering</span>
              </label>
              <label className="flex items-center gap-3 cursor-pointer">
                <input
                  type="checkbox"
                  checked={form.requires_qa}
                  onChange={(e) => updateField('requires_qa', e.target.checked)}
                  className="rounded"
                />
                <span className="text-sm">Requires QA review before dispatch</span>
              </label>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Link href={`/products/${product.id}`}>
              <Button type="button" variant="outline">
                Cancel
              </Button>
            </Link>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Saving...' : 'Save Changes'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
