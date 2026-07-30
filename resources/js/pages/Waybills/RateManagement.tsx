import { useState, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeft, Plus, Pencil, Trash2 } from 'lucide-react';

interface Provider {
  id: number;
  code: string;
  name: string;
}

interface RateRow {
  id: number;
  courier_code: string;
  courier_name: string;
  courier_zone: string;
  base_fee: number;
  per_kg_fee: number;
  weight_threshold_kg: number;
  cod_fee: number;
  is_active: boolean;
}

interface Props {
  rates: RateRow[];
  providers: Provider[];
}

export default function RateManagement({ rates, providers }: Props) {
  const [rateList, setRateList] = useState<RateRow[]>(rates);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingRate, setEditingRate] = useState<RateRow | null>(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({
    courier_code: '',
    courier_zone: '',
    base_fee: '',
    per_kg_fee: '',
    weight_threshold_kg: '',
    cod_fee: '',
    is_active: true,
  });

  const openCreate = useCallback(() => {
    setEditingRate(null);
    setForm({
      courier_code: providers[0]?.code ?? '',
      courier_zone: '',
      base_fee: '',
      per_kg_fee: '',
      weight_threshold_kg: '',
      cod_fee: '',
      is_active: true,
    });
    setDialogOpen(true);
  }, [providers]);

  const openEdit = useCallback((rate: RateRow) => {
    setEditingRate(rate);
    setForm({
      courier_code: rate.courier_code,
      courier_zone: rate.courier_zone,
      base_fee: String(rate.base_fee),
      per_kg_fee: String(rate.per_kg_fee),
      weight_threshold_kg: String(rate.weight_threshold_kg),
      cod_fee: String(rate.cod_fee),
      is_active: rate.is_active,
    });
    setDialogOpen(true);
  }, []);

  const handleSave = useCallback(
    (e: React.FormEvent) => {
      e.preventDefault();
      setSaving(true);
      const payload = {
        courier_code: form.courier_code,
        courier_zone: form.courier_zone,
        base_fee: parseFloat(form.base_fee) || 0,
        per_kg_fee: parseFloat(form.per_kg_fee) || 0,
        weight_threshold_kg: parseFloat(form.weight_threshold_kg) || 0,
        cod_fee: parseFloat(form.cod_fee) || 0,
        is_active: form.is_active,
      };

      if (editingRate) {
        axios
          .patch(`/couriers/rates/${editingRate.id}`, payload)
          .then(({ data }) => {
            toast.success(data.message);
            setRateList((prev) =>
              prev.map((r) =>
                r.id === editingRate.id ? { ...r, ...payload, courier_name: r.courier_name } : r
              )
            );
            setDialogOpen(false);
          })
          .catch(() => toast.error('Failed to update rate'))
          .finally(() => setSaving(false));
      } else {
        axios
          .post('/couriers/rates', payload)
          .then(({ data }) => {
            toast.success(data.message);
            const provider = providers.find((p) => p.code === payload.courier_code);
            setRateList((prev) => [
              ...prev,
              {
                id: data.rate.id,
                courier_code: payload.courier_code,
                courier_name: provider?.name ?? payload.courier_code,
                courier_zone: payload.courier_zone,
                base_fee: payload.base_fee,
                per_kg_fee: payload.per_kg_fee,
                weight_threshold_kg: payload.weight_threshold_kg,
                cod_fee: payload.cod_fee,
                is_active: payload.is_active,
              },
            ]);
            setDialogOpen(false);
          })
          .catch(() => toast.error('Failed to save rate'))
          .finally(() => setSaving(false));
      }
    },
    [form, editingRate, providers]
  );

  const handleDelete = useCallback((rate: RateRow) => {
    if (!confirm(`Delete rate for ${rate.courier_name} — Zone ${rate.courier_zone}?`)) return;
    axios
      .delete(`/couriers/rates/${rate.id}`)
      .then(({ data }) => {
        toast.success(data.message);
        setRateList((prev) => prev.filter((r) => r.id !== rate.id));
      })
      .catch(() => toast.error('Failed to delete rate'));
  }, []);

  return (
    <AppLayout>
      <Head title="Rate Management" />

      <div className="space-y-4 p-6">
        <div className="flex items-center gap-3">
          <Link href="/couriers/compare-rates">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="h-4 w-4 mr-1" />
              Back to Comparison
            </Button>
          </Link>
          <div className="flex-1">
            <h1 className="text-xl font-bold font-display">Rate Management</h1>
            <p className="text-sm text-muted-foreground">
              Manage shipping rates per courier and zone
            </p>
          </div>
          <Button onClick={openCreate}>
            <Plus className="h-4 w-4 mr-1" />
            Add Rate
          </Button>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Shipping Rates</CardTitle>
            <CardDescription>
              {rateList.length} rate{rateList.length !== 1 ? 's' : ''} configured
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Courier</TableHead>
                  <TableHead>Zone</TableHead>
                  <TableHead className="text-right">Base Fee</TableHead>
                  <TableHead className="text-right">Per Kg</TableHead>
                  <TableHead className="text-right">Weight Threshold</TableHead>
                  <TableHead className="text-right">COD Fee</TableHead>
                  <TableHead className="text-center">Active</TableHead>
                  <TableHead className="text-center">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rateList.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                      No rates configured. Click "Add Rate" to create one.
                    </TableCell>
                  </TableRow>
                ) : (
                  rateList.map((rate) => (
                    <TableRow key={rate.id}>
                      <TableCell className="font-medium">{rate.courier_name}</TableCell>
                      <TableCell className="font-mono text-sm">{rate.courier_zone}</TableCell>
                      <TableCell className="text-right text-sm">
                        ₱
                        {Number(rate.base_fee).toLocaleString('en-PH', {
                          minimumFractionDigits: 2,
                        })}
                      </TableCell>
                      <TableCell className="text-right text-sm">
                        ₱
                        {Number(rate.per_kg_fee).toLocaleString('en-PH', {
                          minimumFractionDigits: 2,
                        })}
                      </TableCell>
                      <TableCell className="text-right text-sm">
                        {Number(rate.weight_threshold_kg).toLocaleString('en-PH')} kg
                      </TableCell>
                      <TableCell className="text-right text-sm">
                        {rate.cod_fee > 0
                          ? `₱${Number(rate.cod_fee).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                          : '—'}
                      </TableCell>
                      <TableCell className="text-center">
                        <span
                          className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
                            rate.is_active
                              ? 'bg-success/10 text-success'
                              : 'bg-muted text-muted-foreground'
                          }`}
                        >
                          {rate.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-1">
                          <Button variant="ghost" size="sm" onClick={() => openEdit(rate)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => handleDelete(rate)}>
                            <Trash2 className="h-3.5 w-3.5 text-destructive" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Create/Edit Dialog */}
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent className="sm:max-w-[480px]">
            <DialogHeader>
              <DialogTitle>{editingRate ? 'Edit Rate' : 'Add New Rate'}</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSave} className="space-y-4">
              <div>
                <label className="text-sm font-medium">Courier</label>
                <Select
                  value={form.courier_code}
                  onValueChange={(v) => setForm({ ...form, courier_code: v })}
                  disabled={!!editingRate}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Select courier" />
                  </SelectTrigger>
                  <SelectContent>
                    {providers.map((p) => (
                      <SelectItem key={p.id} value={p.code}>
                        {p.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label className="text-sm font-medium">Zone</label>
                <Input
                  value={form.courier_zone}
                  onChange={(e) => setForm({ ...form, courier_zone: e.target.value })}
                  placeholder="e.g. METRO_MANILA, ZONE_1"
                  required
                  disabled={!!editingRate}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="text-sm font-medium">Base Fee (₱)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.base_fee}
                    onChange={(e) => setForm({ ...form, base_fee: e.target.value })}
                    required
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">Per Kg Fee (₱)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.per_kg_fee}
                    onChange={(e) => setForm({ ...form, per_kg_fee: e.target.value })}
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">Weight Threshold (kg)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.weight_threshold_kg}
                    onChange={(e) => setForm({ ...form, weight_threshold_kg: e.target.value })}
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">COD Fee (₱)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.cod_fee}
                    onChange={(e) => setForm({ ...form, cod_fee: e.target.value })}
                  />
                </div>
              </div>
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="is_active"
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  className="rounded border-input"
                />
                <label htmlFor="is_active" className="text-sm">
                  Active
                </label>
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setDialogOpen(false)}>
                  Cancel
                </Button>
                <Button type="submit" disabled={saving}>
                  {saving ? 'Saving...' : editingRate ? 'Update' : 'Create'}
                </Button>
              </div>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
