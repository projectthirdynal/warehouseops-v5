import { useEffect, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Building2, Edit2, MapPin, Plus, PowerOff, Trash2 } from 'lucide-react';
import { formatCurrency } from '@/lib/utils';

interface WarehouseLocation {
  id: number;
  code: string;
  name?: string;
  type: string;
  capacity?: number;
  is_active: boolean;
}

interface WarehouseRow {
  id: number;
  name: string;
  code: string;
  address?: string;
  contact_person?: string;
  contact_phone?: string;
  is_active: boolean;
  is_default: boolean;
  locations: WarehouseLocation[];
}

interface Props {
  warehouses: WarehouseRow[];
  stock_by_warehouse: Record<number, number>;
  supply_stock_by_warehouse: Record<number, number>;
  stock_value_by_warehouse: Record<number, number>;
}

export default function WarehousesIndex({
  warehouses = [],
  stock_by_warehouse = {},
  supply_stock_by_warehouse = {},
  stock_value_by_warehouse = {},
}: Props) {
  const [whOpen, setWhOpen]       = useState(false);
  const [editWh, setEditWh]       = useState<WarehouseRow | null>(null);
  const [locOpen, setLocOpen]     = useState<number | null>(null);

  const whForm = useForm({
    name: '', code: '', address: '', contact_person: '', contact_phone: '',
    is_active: true, is_default: false,
  });

  useEffect(() => {
    if (editWh) {
      whForm.setData({
        name: editWh.name, code: editWh.code, address: editWh.address ?? '',
        contact_person: editWh.contact_person ?? '', contact_phone: editWh.contact_phone ?? '',
        is_active: editWh.is_active, is_default: editWh.is_default,
      });
    } else {
      whForm.reset();
    }
    whForm.clearErrors();
  }, [editWh, whOpen]);

  function submitWarehouse(e: React.FormEvent) {
    e.preventDefault();
    if (editWh) {
      whForm.put(`/warehouses/${editWh.id}`, { onSuccess: () => { setWhOpen(false); setEditWh(null); } });
    } else {
      whForm.post('/warehouses', { onSuccess: () => { setWhOpen(false); whForm.reset(); } });
    }
  }

  function openCreate() { setEditWh(null); setWhOpen(true); }
  function openEdit(wh: WarehouseRow) { setEditWh(wh); setWhOpen(true); }

  function toggleActive(wh: WarehouseRow) {
    if (!confirm(`${wh.is_active ? 'Deactivate' : 'Activate'} warehouse "${wh.name}"?`)) return;
    router.post(`/warehouses/${wh.id}/toggle`, {}, { preserveState: false });
  }

  return (
    <AppLayout>
      <Head title="Warehouses" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Warehouses</h1>
            <p className="text-sm text-muted-foreground">Manage warehouses, locations, and stock distribution.</p>
          </div>
          <Button onClick={openCreate}><Plus className="mr-2 h-4 w-4" />New Warehouse</Button>
        </div>

        {/* Summary cards */}
        <div className="grid gap-4 sm:grid-cols-3">
          <SummaryCard label="Total Warehouses"  value={warehouses.length} />
          <SummaryCard label="Active"             value={warehouses.filter(w => w.is_active).length} tone="ok" />
          <SummaryCard label="Total Stock Value"  value={formatCurrency(Object.values(stock_value_by_warehouse ?? {}).reduce((a, b) => Number(a) + Number(b), 0))} />
        </div>

        {warehouses.map(wh => (
          <Card key={wh.id} className={!wh.is_active ? 'opacity-60' : ''}>
            <CardHeader className="flex flex-row items-start justify-between pb-3">
              <div className="space-y-1">
                <CardTitle className="flex flex-wrap items-center gap-2 text-lg">
                  <Building2 className="h-5 w-5 text-blue-500" />
                  {wh.name}
                  <span className="font-mono text-xs text-muted-foreground">({wh.code})</span>
                  {wh.is_default && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Default</span>}
                  {!wh.is_active && <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs text-gray-600">Inactive</span>}
                </CardTitle>
                {wh.address && <p className="text-xs text-muted-foreground">{wh.address}</p>}
                {(wh.contact_person || wh.contact_phone) && (
                  <p className="text-xs text-muted-foreground">
                    {[wh.contact_person, wh.contact_phone].filter(Boolean).join(' · ')}
                  </p>
                )}
                <div className="flex flex-wrap gap-4 pt-1 text-xs">
                  <span className="text-muted-foreground">Products: <strong>{Number(stock_by_warehouse[wh.id] ?? 0).toLocaleString()} units</strong></span>
                  <span className="text-muted-foreground">Materials: <strong>{Number(supply_stock_by_warehouse[wh.id] ?? 0).toLocaleString()} units</strong></span>
                  <span className="text-muted-foreground">Value: <strong className="text-emerald-700">{formatCurrency(Number(stock_value_by_warehouse[wh.id] ?? 0))}</strong></span>
                </div>
              </div>
              <div className="flex shrink-0 gap-1">
                <Button variant="ghost" size="icon" onClick={() => openEdit(wh)} title="Edit warehouse">
                  <Edit2 className="h-4 w-4" />
                </Button>
                <Button variant="ghost" size="icon" onClick={() => toggleActive(wh)} title={wh.is_active ? 'Deactivate' : 'Activate'}>
                  <PowerOff className={`h-4 w-4 ${wh.is_active ? 'text-red-500' : 'text-green-500'}`} />
                </Button>
                <Dialog open={locOpen === wh.id} onOpenChange={(o) => setLocOpen(o ? wh.id : null)}>
                  <DialogTrigger asChild>
                    <Button variant="outline" size="sm"><Plus className="mr-1 h-3.5 w-3.5" />Location</Button>
                  </DialogTrigger>
                  <LocationDialog warehouseId={wh.id} onClose={() => setLocOpen(null)} />
                </Dialog>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-32">Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead className="w-24">Type</TableHead>
                    <TableHead className="w-28 text-right">Capacity</TableHead>
                    <TableHead className="w-20">Active</TableHead>
                    <TableHead className="w-16"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {wh.locations.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="py-6 text-center text-sm text-muted-foreground">
                        <MapPin className="mx-auto mb-1 h-5 w-5 opacity-30" />No storage locations defined yet
                      </TableCell>
                    </TableRow>
                  ) : wh.locations.map(loc => (
                    <TableRow key={loc.id} className={!loc.is_active ? 'opacity-50' : ''}>
                      <TableCell className="font-mono text-sm">{loc.code}</TableCell>
                      <TableCell className="text-sm">{loc.name ?? '—'}</TableCell>
                      <TableCell>
                        <span className={`rounded px-2 py-0.5 text-xs ${
                          loc.type === 'BIN' ? 'bg-blue-100 text-blue-700' :
                          loc.type === 'ZONE' ? 'bg-purple-100 text-purple-700' :
                          'bg-gray-100 text-gray-700'
                        }`}>{loc.type}</span>
                      </TableCell>
                      <TableCell className="text-right text-sm">{loc.capacity ? loc.capacity.toLocaleString() : '—'}</TableCell>
                      <TableCell>
                        <span className={`rounded-full px-2 py-0.5 text-xs ${loc.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500'}`}>
                          {loc.is_active ? 'Yes' : 'No'}
                        </span>
                      </TableCell>
                      <TableCell>
                        <Button size="icon" variant="ghost" onClick={() => {
                          if (confirm('Remove this location? This cannot be undone if stock is assigned to it.'))
                            router.delete(`/warehouses/locations/${loc.id}`);
                        }}>
                          <Trash2 className="h-3.5 w-3.5 text-red-500" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        ))}

        {warehouses.length === 0 && (
          <div className="rounded-lg border-2 border-dashed p-12 text-center text-muted-foreground">
            <Building2 className="mx-auto mb-3 h-10 w-10 opacity-30" />
            <p className="font-medium">No warehouses yet</p>
            <p className="mt-1 text-sm">Create your first warehouse to start tracking inventory.</p>
            <Button className="mt-4" onClick={openCreate}><Plus className="mr-2 h-4 w-4" />Create Warehouse</Button>
          </div>
        )}
      </div>

      {/* Warehouse Create/Edit Dialog */}
      <Dialog open={whOpen} onOpenChange={(o) => { if (!o) { setWhOpen(false); setEditWh(null); } }}>
        <DialogContent>
          <DialogHeader><DialogTitle>{editWh ? 'Edit Warehouse' : 'Create Warehouse'}</DialogTitle></DialogHeader>
          <form onSubmit={submitWarehouse} className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label>Name *</Label>
                <Input value={whForm.data.name} onChange={e => whForm.setData('name', e.target.value)} required />
                {whForm.errors.name && <p className="text-xs text-red-600">{whForm.errors.name}</p>}
              </div>
              <div className="space-y-1">
                <Label>Code *</Label>
                <Input value={whForm.data.code} onChange={e => whForm.setData('code', e.target.value.toUpperCase())}
                  required maxLength={20} className="font-mono uppercase" />
                {whForm.errors.code && <p className="text-xs text-red-600">{whForm.errors.code}</p>}
              </div>
            </div>
            <div className="space-y-1">
              <Label>Address</Label>
              <Input value={whForm.data.address} onChange={e => whForm.setData('address', e.target.value)} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <Label>Contact Person</Label>
                <Input value={whForm.data.contact_person} onChange={e => whForm.setData('contact_person', e.target.value)} />
              </div>
              <div className="space-y-1">
                <Label>Contact Phone</Label>
                <Input value={whForm.data.contact_phone} onChange={e => whForm.setData('contact_phone', e.target.value)} />
              </div>
            </div>
            <div className="flex gap-4">
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={whForm.data.is_default} onChange={e => whForm.setData('is_default', e.target.checked)} />
                Set as default warehouse
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={whForm.data.is_active} onChange={e => whForm.setData('is_active', e.target.checked)} />
                Active
              </label>
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => { setWhOpen(false); setEditWh(null); }}>Cancel</Button>
              <Button type="submit" disabled={whForm.processing}>{editWh ? 'Save Changes' : 'Create'}</Button>
            </div>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

function LocationDialog({ warehouseId, onClose }: { warehouseId: number; onClose: () => void }) {
  const form = useForm({ code: '', name: '', type: 'SHELF', capacity: '', is_active: true });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post(`/warehouses/${warehouseId}/locations`, {
      onSuccess: () => { onClose(); form.reset(); },
    });
  }

  return (
    <DialogContent>
      <DialogHeader><DialogTitle>Add Storage Location</DialogTitle></DialogHeader>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1">
            <Label>Code *</Label>
            <Input value={form.data.code} onChange={e => form.setData('code', e.target.value.toUpperCase())} required className="font-mono uppercase" />
            {form.errors.code && <p className="text-xs text-red-600">{form.errors.code}</p>}
          </div>
          <div className="space-y-1">
            <Label>Type *</Label>
            <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="BIN">Bin</SelectItem>
                <SelectItem value="SHELF">Shelf</SelectItem>
                <SelectItem value="ZONE">Zone</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
        <div className="space-y-1">
          <Label>Name</Label>
          <Input value={form.data.name} onChange={e => form.setData('name', e.target.value)} placeholder="e.g. Shelf A Row 1" />
        </div>
        <div className="space-y-1">
          <Label>Capacity (units)</Label>
          <Input type="number" min={0} value={form.data.capacity} onChange={e => form.setData('capacity', e.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.data.is_active} onChange={e => form.setData('is_active', e.target.checked)} />
          Active
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={form.processing}>Add Location</Button>
        </div>
      </form>
    </DialogContent>
  );
}

function SummaryCard({ label, value, tone }: { label: string; value: string | number; tone?: 'ok' }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${tone === 'ok' ? 'text-emerald-700' : ''}`}>{value}</p>
      </CardContent>
    </Card>
  );
}
