import { useState } from 'react';
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
import { Building2, Plus, MapPin, Trash2 } from 'lucide-react';

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
}

export default function WarehousesIndex({ warehouses }: Props) {
  const [whOpen, setWhOpen] = useState(false);
  const [locOpen, setLocOpen] = useState<number | null>(null);

  const whForm = useForm({
    name: '', code: '', address: '', contact_person: '', contact_phone: '',
    is_active: true, is_default: false,
  });

  function submitWarehouse(e: React.FormEvent) {
    e.preventDefault();
    whForm.post('/warehouses', {
      onSuccess: () => { setWhOpen(false); whForm.reset(); },
    });
  }

  return (
    <AppLayout>
      <Head title="Warehouses" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Warehouses</h1>
            <p className="text-sm text-muted-foreground">Manage warehouses and storage locations.</p>
          </div>
          <Dialog open={whOpen} onOpenChange={setWhOpen}>
            <DialogTrigger asChild>
              <Button><Plus className="mr-2 h-4 w-4" />New Warehouse</Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader><DialogTitle>Create Warehouse</DialogTitle></DialogHeader>
              <form onSubmit={submitWarehouse} className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <Label>Name *</Label>
                    <Input value={whForm.data.name} onChange={e => whForm.setData('name', e.target.value)} required />
                  </div>
                  <div className="space-y-1">
                    <Label>Code *</Label>
                    <Input value={whForm.data.code} onChange={e => whForm.setData('code', e.target.value.toUpperCase())} required maxLength={20} className="uppercase font-mono" />
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
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={whForm.data.is_default} onChange={e => whForm.setData('is_default', e.target.checked)} />
                  Set as default warehouse
                </label>
                <div className="flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={() => setWhOpen(false)}>Cancel</Button>
                  <Button type="submit" disabled={whForm.processing}>Create</Button>
                </div>
              </form>
            </DialogContent>
          </Dialog>
        </div>

        {warehouses.map(wh => (
          <Card key={wh.id}>
            <CardHeader className="flex flex-row items-center justify-between pb-3">
              <div>
                <CardTitle className="flex items-center gap-2 text-lg">
                  <Building2 className="h-5 w-5 text-blue-500" />
                  {wh.name}
                  <span className="font-mono text-xs text-muted-foreground">({wh.code})</span>
                  {wh.is_default && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">Default</span>}
                  {!wh.is_active && <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs">Inactive</span>}
                </CardTitle>
                {wh.address && <p className="mt-1 text-xs text-muted-foreground">{wh.address}</p>}
              </div>
              <Dialog open={locOpen === wh.id} onOpenChange={(o) => setLocOpen(o ? wh.id : null)}>
                <DialogTrigger asChild>
                  <Button variant="outline" size="sm"><Plus className="mr-1 h-3.5 w-3.5" />Location</Button>
                </DialogTrigger>
                <LocationDialog warehouseId={wh.id} onClose={() => setLocOpen(null)} />
              </Dialog>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-32">Code</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead className="w-24">Type</TableHead>
                    <TableHead className="w-24 text-right">Capacity</TableHead>
                    <TableHead className="w-24"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {wh.locations.length === 0 ? (
                    <TableRow><TableCell colSpan={5} className="py-8 text-center text-sm text-muted-foreground">
                      <MapPin className="mx-auto mb-1 h-5 w-5 opacity-30" />
                      No locations defined yet
                    </TableCell></TableRow>
                  ) : wh.locations.map(loc => (
                    <TableRow key={loc.id}>
                      <TableCell className="font-mono text-sm">{loc.code}</TableCell>
                      <TableCell className="text-sm">{loc.name ?? '—'}</TableCell>
                      <TableCell><span className="rounded bg-gray-100 px-2 py-0.5 text-xs">{loc.type}</span></TableCell>
                      <TableCell className="text-right text-sm">{loc.capacity ?? '—'}</TableCell>
                      <TableCell>
                        <Button size="icon" variant="ghost" onClick={() => {
                          if (confirm('Remove this location?')) router.delete(`/warehouses/locations/${loc.id}`);
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
      </div>
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
      <DialogHeader><DialogTitle>Add Location</DialogTitle></DialogHeader>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-3">
          <div className="space-y-1">
            <Label>Code *</Label>
            <Input value={form.data.code} onChange={e => form.setData('code', e.target.value.toUpperCase())} required className="font-mono uppercase" />
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
          <Input value={form.data.name} onChange={e => form.setData('name', e.target.value)} />
        </div>
        <div className="space-y-1">
          <Label>Capacity</Label>
          <Input type="number" min={0} value={form.data.capacity} onChange={e => form.setData('capacity', e.target.value)} />
        </div>
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={form.processing}>Add</Button>
        </div>
      </form>
    </DialogContent>
  );
}
