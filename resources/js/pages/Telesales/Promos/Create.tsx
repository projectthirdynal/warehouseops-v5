import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Save } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface PromoTypeOption {
  value: string;
  label: string;
  description: string;
}

interface Props {
  promoTypes: PromoTypeOption[];
  promo?: PromoData;
}

interface PromoData {
  id: number;
  promo_code: string;
  name: string;
  description: string | null;
  type: string;
  trigger_quantity: number;
  free_quantity: number;
  discount_percentage: string;
  free_item_name: string | null;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
}

export default function PromoCreate({ promoTypes, promo }: Props) {
  const isEdit = !!promo;
  const [form, setForm] = useState({
    promo_code: promo?.promo_code || '',
    name: promo?.name || '',
    description: promo?.description || '',
    type: promo?.type || 'FREEBIE',
    trigger_quantity: promo?.trigger_quantity || 1,
    free_quantity: promo?.free_quantity || 1,
    discount_percentage: promo?.discount_percentage || 0,
    free_item_name: promo?.free_item_name || '',
    is_active: promo?.is_active ?? true,
    starts_at: promo?.starts_at?.split('T')[0] || '',
    ends_at: promo?.ends_at?.split('T')[0] || '',
  });
  const [isSaving, setIsSaving] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);

    const url = isEdit ? `/api/telesales/promos/${promo!.id}` : '/api/telesales/promos';
    const method = isEdit ? 'PUT' : 'POST';

    try {
      const response = await fetch(url, {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(form),
      });

      if (!response.ok) {
        const err = await response.json();
        alert(err.message || 'Failed to save promo');
        return;
      }

      router.visit('/telesales/promos');
    } catch {
      alert('Failed to save promo');
    } finally {
      setIsSaving(false);
    }
  };

  const showBundleFields = form.type === 'BUNDLE';
  const showFreebieFields = form.type === 'FREEBIE';
  const showDiscountFields = form.type === 'DISCOUNT';

  return (
    <TelesalesLayout>
      <Head title={isEdit ? 'Edit Promo' : 'New Promo'} />
      <div className="space-y-6 p-6 max-w-2xl">
        <div className="flex items-center gap-3">
          <Button variant="ghost" size="sm" onClick={() => router.visit('/telesales/promos')}>
            <ArrowLeft className="h-4 w-4 mr-1" />
            Back
          </Button>
          <h1 className="text-2xl font-bold tracking-tight">
            {isEdit ? 'Edit Promo' : 'New Promo'}
          </h1>
        </div>

        <form onSubmit={handleSubmit}>
          <Card>
            <CardHeader>
              <CardTitle>Promo Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Name */}
              <div className="space-y-2">
                <Label htmlFor="name">Promo Name *</Label>
                <Input
                  id="name"
                  required
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="e.g. Buy 1 Take 1 Black Garlic"
                />
              </div>

              {/* Code */}
              <div className="space-y-2">
                <Label htmlFor="promo_code">Promo Code (optional)</Label>
                <Input
                  id="promo_code"
                  value={form.promo_code}
                  onChange={(e) => setForm({ ...form, promo_code: e.target.value.toUpperCase() })}
                  placeholder="Auto-generated if blank"
                  className="font-mono"
                />
              </div>

              {/* Type */}
              <div className="space-y-2">
                <Label>Promo Type *</Label>
                <Select value={form.type} onValueChange={(v) => setForm({ ...form, type: v })}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {promoTypes.map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label} — {t.description}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Description */}
              <div className="space-y-2">
                <Label htmlFor="description">Description</Label>
                <Textarea
                  id="description"
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  placeholder="What does this promo offer?"
                  rows={2}
                />
              </div>

              {/* Type-specific fields */}
              {showBundleFields && (
                <div className="grid grid-cols-2 gap-4 p-3 bg-blue-50 rounded-lg">
                  <div className="space-y-2">
                    <Label htmlFor="trigger_quantity">Trigger Quantity (Buy X)</Label>
                    <Input
                      id="trigger_quantity"
                      type="number"
                      min={1}
                      value={form.trigger_quantity}
                      onChange={(e) =>
                        setForm({ ...form, trigger_quantity: parseInt(e.target.value) || 1 })
                      }
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="free_quantity">Free Quantity (Take Y)</Label>
                    <Input
                      id="free_quantity"
                      type="number"
                      min={0}
                      value={form.free_quantity}
                      onChange={(e) =>
                        setForm({ ...form, free_quantity: parseInt(e.target.value) || 0 })
                      }
                    />
                  </div>
                  <p className="col-span-2 text-xs text-muted-foreground">
                    Customer buys {form.trigger_quantity} unit(s), gets {form.free_quantity} free of
                    the same product.
                  </p>
                </div>
              )}

              {showFreebieFields && (
                <div className="space-y-2 p-3 bg-purple-50 rounded-lg">
                  <div className="space-y-2">
                    <Label htmlFor="free_item_name">Free Item Name</Label>
                    <Input
                      id="free_item_name"
                      value={form.free_item_name}
                      onChange={(e) => setForm({ ...form, free_item_name: e.target.value })}
                      placeholder="e.g. Sample Pack, Free T-shirt"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="free_quantity">Free Quantity</Label>
                    <Input
                      id="free_quantity"
                      type="number"
                      min={1}
                      value={form.free_quantity}
                      onChange={(e) =>
                        setForm({ ...form, free_quantity: parseInt(e.target.value) || 1 })
                      }
                    />
                  </div>
                </div>
              )}

              {showDiscountFields && (
                <div className="space-y-2 p-3 bg-green-50 rounded-lg">
                  <Label htmlFor="discount_percentage">Discount Percentage (%)</Label>
                  <Input
                    id="discount_percentage"
                    type="number"
                    min={0}
                    max={100}
                    step="0.01"
                    value={form.discount_percentage}
                    onChange={(e) =>
                      setForm({ ...form, discount_percentage: parseFloat(e.target.value) || 0 })
                    }
                  />
                </div>
              )}

              {/* Active toggle */}
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  className="rounded"
                />
                <Label htmlFor="is_active">Active (visible to agents)</Label>
              </div>

              {/* Date range */}
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="starts_at">Starts At (optional)</Label>
                  <Input
                    id="starts_at"
                    type="date"
                    value={form.starts_at}
                    onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="ends_at">Ends At (optional)</Label>
                  <Input
                    id="ends_at"
                    type="date"
                    value={form.ends_at}
                    onChange={(e) => setForm({ ...form, ends_at: e.target.value })}
                  />
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2 mt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => router.visit('/telesales/promos')}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={isSaving}>
              <Save className="h-4 w-4 mr-1" />
              {isSaving ? 'Saving...' : isEdit ? 'Update Promo' : 'Create Promo'}
            </Button>
          </div>
        </form>
      </div>
    </TelesalesLayout>
  );
}
