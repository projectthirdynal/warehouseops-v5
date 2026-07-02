import { useEffect } from 'react';
import { toast } from 'sonner';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
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
import { ArrowLeft } from 'lucide-react';

interface Uom {
  id: number;
  name: string;
  abbreviation: string;
}
interface Warehouse {
  id: number;
  name: string;
  code: string;
}
interface User {
  id: number;
  name: string;
}

interface CapexAsset {
  id: number;
  asset_code: string;
  name: string;
  description?: string;
  category: string;
  depreciation_years: number;
  purchase_date: string;
  acquisition_cost: number;
  salvage_value: number;
  warehouse_id?: number;
  assigned_to?: number;
  department?: string;
  uom_id?: number;
  quantity: number;
}

interface Props {
  asset?: CapexAsset;
  categories: Record<string, string>;
  warehouses: Warehouse[];
  uoms: Uom[];
  users: User[];
}

export default function AssetCreate({ asset, categories, warehouses, uoms, users }: Props) {
  const isEdit = !!asset;

  const form = useForm({
    asset_code: asset?.asset_code ?? '',
    name: asset?.name ?? '',
    description: asset?.description ?? '',
    category: asset?.category ?? 'OTHER',
    depreciation_years: String(asset?.depreciation_years ?? '3'),
    purchase_date: asset?.purchase_date ?? '',
    acquisition_cost: Number(asset?.acquisition_cost ?? 0),
    salvage_value: Number(asset?.salvage_value ?? 0),
    warehouse_id: asset?.warehouse_id ? String(asset.warehouse_id) : '',
    assigned_to: asset?.assigned_to ? String(asset.assigned_to) : '',
    department: asset?.department ?? '',
    uom_id: asset?.uom_id ? String(asset.uom_id) : '',
    quantity: asset?.quantity ?? 1,
  });

  useEffect(() => {
    if (!isEdit) return;
    form.setData({
      asset_code: asset.asset_code,
      name: asset.name,
      description: asset.description ?? '',
      category: asset.category,
      depreciation_years: String(asset.depreciation_years),
      purchase_date: asset.purchase_date,
      acquisition_cost: Number(asset.acquisition_cost),
      salvage_value: Number(asset.salvage_value),
      warehouse_id: asset.warehouse_id ? String(asset.warehouse_id) : '',
      assigned_to: asset.assigned_to ? String(asset.assigned_to) : '',
      department: asset.department ?? '',
      uom_id: asset.uom_id ? String(asset.uom_id) : '',
      quantity: asset.quantity,
    });
  }, []);

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (isEdit) {
      form.put(`/inventory/assets/${asset.id}`, {
        onSuccess: () => toast.success('Asset updated.'),
        onError: () => toast.error('Failed to update asset.'),
      });
    } else {
      form.post('/inventory/assets', {
        onSuccess: () => toast.success('Asset created.'),
        onError: () => toast.error('Failed to create asset.'),
      });
    }
  }

  const depYears = parseInt(form.data.depreciation_years) || 0;
  const annualDep =
    depYears > 0
      ? ((form.data.acquisition_cost - form.data.salvage_value) / depYears).toFixed(2)
      : '—';

  return (
    <AppLayout>
      <Head title={isEdit ? 'Edit Asset' : 'New CAPEX Asset'} />
      <div className="max-w-2xl mx-auto space-y-4 p-6">
        <div>
          <Link
            href="/inventory/assets"
            className="mb-1 flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="h-3 w-3" /> CAPEX Assets
          </Link>
          <h1 className="text-2xl font-bold">{isEdit ? 'Edit Asset' : 'New CAPEX Asset'}</h1>
        </div>

        <form onSubmit={submit} className="space-y-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Asset Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label>Asset Code *</Label>
                  <Input
                    value={form.data.asset_code}
                    onChange={(e) => form.setData('asset_code', e.target.value.toUpperCase())}
                    className="font-mono uppercase"
                    required
                  />
                  {form.errors.asset_code && (
                    <p className="text-xs text-destructive">{form.errors.asset_code}</p>
                  )}
                </div>
                <div className="space-y-1">
                  <Label>Name *</Label>
                  <Input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    required
                  />
                  {form.errors.name && (
                    <p className="text-xs text-destructive">{form.errors.name}</p>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label>Category *</Label>
                  <Select
                    value={form.data.category}
                    onValueChange={(v) => form.setData('category', v)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(categories).map(([k, v]) => (
                        <SelectItem key={k} value={k}>
                          {v}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {form.errors.category && (
                    <p className="text-xs text-destructive">{form.errors.category}</p>
                  )}
                </div>
                <div className="space-y-1">
                  <Label>Depreciation Period *</Label>
                  <Select
                    value={form.data.depreciation_years}
                    onValueChange={(v) => form.setData('depreciation_years', v)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="1">1 Year</SelectItem>
                      <SelectItem value="2">2 Years</SelectItem>
                      <SelectItem value="3">3 Years</SelectItem>
                    </SelectContent>
                  </Select>
                  {form.errors.depreciation_years && (
                    <p className="text-xs text-destructive">{form.errors.depreciation_years}</p>
                  )}
                </div>
              </div>

              <div className="space-y-1">
                <Label>Description</Label>
                <Textarea
                  rows={2}
                  value={form.data.description}
                  onChange={(e) => form.setData('description', e.target.value)}
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Financials</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="grid grid-cols-3 gap-3">
                <div className="space-y-1">
                  <Label>Purchase Date *</Label>
                  <Input
                    type="date"
                    value={form.data.purchase_date}
                    onChange={(e) => form.setData('purchase_date', e.target.value)}
                    required
                  />
                  {form.errors.purchase_date && (
                    <p className="text-xs text-destructive">{form.errors.purchase_date}</p>
                  )}
                </div>
                <div className="space-y-1">
                  <Label>Acquisition Cost *</Label>
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={form.data.acquisition_cost}
                    onChange={(e) => form.setData('acquisition_cost', Number(e.target.value))}
                    required
                  />
                  {form.errors.acquisition_cost && (
                    <p className="text-xs text-destructive">{form.errors.acquisition_cost}</p>
                  )}
                </div>
                <div className="space-y-1">
                  <Label>Salvage Value</Label>
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={form.data.salvage_value}
                    onChange={(e) => form.setData('salvage_value', Number(e.target.value))}
                  />
                </div>
              </div>
              {depYears > 0 && (
                <p className="text-sm text-muted-foreground rounded-md bg-muted px-3 py-2">
                  Annual straight-line depreciation: <strong>₱{annualDep}</strong> / year for{' '}
                  {depYears} year(s)
                </p>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Assignment & Location</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label>Warehouse</Label>
                  <Select
                    value={form.data.warehouse_id || 'none'}
                    onValueChange={(v) => form.setData('warehouse_id', v === 'none' ? '' : v)}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select..." />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">— None —</SelectItem>
                      {warehouses.map((w) => (
                        <SelectItem key={w.id} value={String(w.id)}>
                          {w.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label>Assigned To</Label>
                  <Select
                    value={form.data.assigned_to || 'none'}
                    onValueChange={(v) => form.setData('assigned_to', v === 'none' ? '' : v)}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select user..." />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">— Unassigned —</SelectItem>
                      {users.map((u) => (
                        <SelectItem key={u.id} value={String(u.id)}>
                          {u.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="grid grid-cols-3 gap-3">
                <div className="space-y-1">
                  <Label>Department</Label>
                  <Input
                    value={form.data.department}
                    onChange={(e) => form.setData('department', e.target.value)}
                    placeholder="e.g. Operations"
                  />
                </div>
                <div className="space-y-1">
                  <Label>UoM</Label>
                  <Select
                    value={form.data.uom_id || 'none'}
                    onValueChange={(v) => form.setData('uom_id', v === 'none' ? '' : v)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">— None —</SelectItem>
                      {uoms.map((u) => (
                        <SelectItem key={u.id} value={String(u.id)}>
                          {u.name} ({u.abbreviation})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label>Quantity</Label>
                  <Input
                    type="number"
                    min={1}
                    value={form.data.quantity}
                    onChange={(e) => form.setData('quantity', Number(e.target.value))}
                  />
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-3">
            <Link href="/inventory/assets">
              <Button type="button" variant="outline">
                Cancel
              </Button>
            </Link>
            <Button type="submit" disabled={form.processing}>
              {isEdit ? 'Save Changes' : 'Create Asset'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
