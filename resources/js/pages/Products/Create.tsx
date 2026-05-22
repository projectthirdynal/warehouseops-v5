import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, Trash2, ArrowLeft } from 'lucide-react';
import { Link } from '@inertiajs/react';

interface VariantForm {
  variant_name: string;
  sku: string;
  selling_price: string;
  cost_price: string;
  weight_grams: string;
}

interface Props {
  categories: string[];
  brands: string[];
}

export default function ProductCreate({ categories, brands }: Props) {
  const [form, setForm] = useState({
    sku: '',
    name: '',
    brand: '',
    category: '',
    selling_price: '',
    cost_price: '',
    weight_grams: '',
    description: '',
    is_active: true,
    requires_qa: true,
    initial_stock: '',
    reorder_point: '10',
  });

  const [variants, setVariants] = useState<VariantForm[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);

  const updateField = (field: string, value: string | boolean) => {
    setForm((prev) => ({ ...prev, [field]: value }));
  };

  const addVariant = () => {
    setVariants((prev) => [...prev, { variant_name: '', sku: '', selling_price: '', cost_price: '', weight_grams: '' }]);
  };

  const updateVariant = (index: number, field: string, value: string) => {
    setVariants((prev) => prev.map((v, i) => (i === index ? { ...v, [field]: value } : v)));
  };

  const removeVariant = (index: number) => {
    setVariants((prev) => prev.filter((_, i) => i !== index));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setErrors({});

    const payload: Record<string, string | number | boolean | null | object[]> = {
      ...form,
      selling_price: parseFloat(form.selling_price) || 0,
      cost_price: parseFloat(form.cost_price) || 0,
      weight_grams: parseInt(form.weight_grams) || 0,
      initial_stock: parseInt(form.initial_stock) || 0,
      reorder_point: parseInt(form.reorder_point) || 10,
    };

    if (variants.length > 0) {
      payload.variants = variants.map((v) => ({
        ...v,
        selling_price: v.selling_price ? parseFloat(v.selling_price) : null,
        cost_price: v.cost_price ? parseFloat(v.cost_price) : null,
        weight_grams: v.weight_grams ? parseInt(v.weight_grams) : null,
      }));
    }

    router.post('/products', payload as Record<string, string>, {
      onError: (errs) => {
        setErrors(errs);
        setSubmitting(false);
      },
      onFinish: () => setSubmitting(false),
    });
  };

  const inputClass = 'w-full border rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary/20';
  const labelClass = 'block text-sm font-medium mb-1';

  return (
    <AppLayout>
      <div className="max-w-3xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex items-center gap-3">
          <Link href="/products">
            <Button variant="ghost" size="icon"><ArrowLeft className="h-4 w-4" /></Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold">Add Product</h1>
            <p className="text-sm text-muted-foreground">Create a new product in the catalog</p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Basic Info */}
          <Card>
            <CardHeader><CardTitle className="text-base">Product Information</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className={labelClass}>SKU *</label>
                  <input className={inputClass} value={form.sku} onChange={(e) => updateField('sku', e.target.value)} placeholder="e.g. STEM-001" />
                  {errors.sku && <p className="text-xs text-red-500 mt-1">{errors.sku}</p>}
                </div>
                <div>
                  <label className={labelClass}>Product Name *</label>
                  <input className={inputClass} value={form.name} onChange={(e) => updateField('name', e.target.value)} placeholder="e.g. STEM Coffee" />
                  {errors.name && <p className="text-xs text-red-500 mt-1">{errors.name}</p>}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className={labelClass}>Brand</label>
                  <input className={inputClass} list="brands" value={form.brand} onChange={(e) => updateField('brand', e.target.value)} placeholder="e.g. Thirdynal" />
                  <datalist id="brands">{brands.map((b) => <option key={b} value={b} />)}</datalist>
                </div>
                <div>
                  <label className={labelClass}>Category</label>
                  <input className={inputClass} list="categories" value={form.category} onChange={(e) => updateField('category', e.target.value)} placeholder="e.g. Health & Wellness" />
                  <datalist id="categories">{categories.map((c) => <option key={c} value={c} />)}</datalist>
                </div>
              </div>

              <div>
                <label className={labelClass}>Description</label>
                <textarea className={inputClass} rows={3} value={form.description} onChange={(e) => updateField('description', e.target.value)} placeholder="Product description..." />
              </div>
            </CardContent>
          </Card>

          {/* Pricing */}
          <Card>
            <CardHeader><CardTitle className="text-base">Pricing & Weight</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className={labelClass}>Selling Price (PHP) *</label>
                  <input type="number" step="0.01" className={inputClass} value={form.selling_price} onChange={(e) => updateField('selling_price', e.target.value)} placeholder="0.00" />
                  {errors.selling_price && <p className="text-xs text-red-500 mt-1">{errors.selling_price}</p>}
                </div>
                <div>
                  <label className={labelClass}>Cost Price (PHP) *</label>
                  <input type="number" step="0.01" className={inputClass} value={form.cost_price} onChange={(e) => updateField('cost_price', e.target.value)} placeholder="0.00" />
                  {errors.cost_price && <p className="text-xs text-red-500 mt-1">{errors.cost_price}</p>}
                </div>
                <div>
                  <label className={labelClass}>Weight (grams) *</label>
                  <input type="number" className={inputClass} value={form.weight_grams} onChange={(e) => updateField('weight_grams', e.target.value)} placeholder="500" />
                </div>
              </div>
              {form.selling_price && form.cost_price && parseFloat(form.selling_price) > 0 && (
                <p className="text-xs text-muted-foreground">
                  Margin: {((parseFloat(form.selling_price) - parseFloat(form.cost_price)) / parseFloat(form.selling_price) * 100).toFixed(1)}%
                </p>
              )}
            </CardContent>
          </Card>

          {/* Inventory */}
          <Card>
            <CardHeader><CardTitle className="text-base">Inventory</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className={labelClass}>Initial Stock Quantity</label>
                  <input type="number" className={inputClass} value={form.initial_stock} onChange={(e) => updateField('initial_stock', e.target.value)} placeholder="0" />
                </div>
                <div>
                  <label className={labelClass}>Reorder Point</label>
                  <input type="number" className={inputClass} value={form.reorder_point} onChange={(e) => updateField('reorder_point', e.target.value)} placeholder="10" />
                  <p className="text-xs text-muted-foreground mt-1">Alert when stock falls below this level</p>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Variants */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="text-base">Variants</CardTitle>
              <Button type="button" variant="outline" size="sm" onClick={addVariant}>
                <Plus className="h-3 w-3 mr-1" /> Add Variant
              </Button>
            </CardHeader>
            <CardContent>
              {variants.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-4">No variants. Product will be sold as-is.</p>
              ) : (
                <div className="space-y-4">
                  {variants.map((v, i) => (
                    <div key={i} className="p-4 rounded-lg border space-y-3">
                      <div className="flex items-center justify-between">
                        <Badge variant="outline">Variant {i + 1}</Badge>
                        <Button type="button" variant="ghost" size="icon" onClick={() => removeVariant(i)}>
                          <Trash2 className="h-3.5 w-3.5 text-red-500" />
                        </Button>
                      </div>
                      <div className="grid grid-cols-2 gap-3">
                        <div>
                          <label className={labelClass}>Name *</label>
                          <input className={inputClass} value={v.variant_name} onChange={(e) => updateVariant(i, 'variant_name', e.target.value)} placeholder="e.g. 30 capsules" />
                        </div>
                        <div>
                          <label className={labelClass}>SKU *</label>
                          <input className={inputClass} value={v.sku} onChange={(e) => updateVariant(i, 'sku', e.target.value)} placeholder="e.g. STEM-001-30" />
                        </div>
                      </div>
                      <div className="grid grid-cols-3 gap-3">
                        <div>
                          <label className={labelClass}>Price (PHP)</label>
                          <input type="number" step="0.01" className={inputClass} value={v.selling_price} onChange={(e) => updateVariant(i, 'selling_price', e.target.value)} placeholder="Override" />
                        </div>
                        <div>
                          <label className={labelClass}>Cost (PHP)</label>
                          <input type="number" step="0.01" className={inputClass} value={v.cost_price} onChange={(e) => updateVariant(i, 'cost_price', e.target.value)} placeholder="Override" />
                        </div>
                        <div>
                          <label className={labelClass}>Weight (g)</label>
                          <input type="number" className={inputClass} value={v.weight_grams} onChange={(e) => updateVariant(i, 'weight_grams', e.target.value)} placeholder="Override" />
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Settings */}
          <Card>
            <CardHeader><CardTitle className="text-base">Settings</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              <label className="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" checked={form.is_active} onChange={(e) => updateField('is_active', e.target.checked)} className="rounded" />
                <span className="text-sm">Active — available for ordering</span>
              </label>
              <label className="flex items-center gap-3 cursor-pointer">
                <input type="checkbox" checked={form.requires_qa} onChange={(e) => updateField('requires_qa', e.target.checked)} className="rounded" />
                <span className="text-sm">Requires QA review before dispatch</span>
              </label>
            </CardContent>
          </Card>

          {/* Submit */}
          <div className="flex justify-end gap-3">
            <Link href="/products">
              <Button type="button" variant="outline">Cancel</Button>
            </Link>
            <Button type="submit" disabled={submitting}>
              {submitting ? 'Creating...' : 'Create Product'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
