import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { Building2, Plus, Edit2, Search } from 'lucide-react';
import type { PaginatedResponse } from '@/types';

interface Supplier {
  id: number;
  name: string;
  code: string;
  contact_person?: string;
  email?: string;
  phone?: string;
  payment_terms?: string;
  lead_time_days: number;
  is_active: boolean;
}

interface Props {
  suppliers: PaginatedResponse<Supplier>;
  filters: { search?: string; status?: string };
}

export default function SuppliersIndex({ suppliers, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Supplier | null>(null);

  function applyFilters(o: Record<string, string>) {
    router.get('/procurement/suppliers', { ...filters, ...o }, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="Suppliers" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Suppliers</h1>
            <p className="text-sm text-muted-foreground">Vendors used in purchase orders.</p>
          </div>
          <Button onClick={() => { setEditing(null); setOpen(true); }}>
            <Plus className="mr-2 h-4 w-4" />New Supplier
          </Button>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex gap-2">
          <Input placeholder="Search name or code..." value={search} onChange={e => setSearch(e.target.value)} className="w-64" />
          <Button type="submit" variant="secondary" size="sm"><Search className="mr-1 h-3.5 w-3.5" />Search</Button>
        </form>

        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Code</TableHead>
                <TableHead>Contact</TableHead>
                <TableHead>Phone / Email</TableHead>
                <TableHead>Payment Terms</TableHead>
                <TableHead className="text-right">Lead Time</TableHead>
                <TableHead>Status</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {suppliers.data.length === 0 ? (
                <TableRow><TableCell colSpan={8} className="py-12 text-center text-muted-foreground">
                  <Building2 className="mx-auto mb-2 h-8 w-8 opacity-30" />No suppliers found.
                </TableCell></TableRow>
              ) : suppliers.data.map(s => (
                <TableRow key={s.id}>
                  <TableCell className="font-medium">{s.name}</TableCell>
                  <TableCell className="font-mono text-sm">{s.code}</TableCell>
                  <TableCell className="text-sm">{s.contact_person ?? '—'}</TableCell>
                  <TableCell className="text-xs">
                    {s.phone && <div>{s.phone}</div>}
                    {s.email && <div className="text-muted-foreground">{s.email}</div>}
                  </TableCell>
                  <TableCell className="text-sm">{s.payment_terms ?? '—'}</TableCell>
                  <TableCell className="text-right text-sm">{s.lead_time_days}d</TableCell>
                  <TableCell>
                    <span className={`rounded-full px-2 py-0.5 text-xs ${s.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'}`}>
                      {s.is_active ? 'Active' : 'Inactive'}
                    </span>
                  </TableCell>
                  <TableCell>
                    <Button size="sm" variant="ghost" onClick={() => { setEditing(s); setOpen(true); }}>
                      <Edit2 className="h-3.5 w-3.5" />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {suppliers.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: suppliers.last_page }, (_, i) => i + 1).map(p => (
              <Button key={p} size="sm" variant={p === suppliers.current_page ? 'default' : 'outline'}
                onClick={() => applyFilters({ page: String(p) })}>{p}</Button>
            ))}
          </div>
        )}
      </div>

      <SupplierDialog open={open} onClose={() => setOpen(false)} editing={editing} />
    </AppLayout>
  );
}

function SupplierDialog({ open, onClose, editing }: { open: boolean; onClose: () => void; editing: Supplier | null }) {
  const form = useForm({
    name:           editing?.name ?? '',
    code:           editing?.code ?? '',
    contact_person: editing?.contact_person ?? '',
    email:          editing?.email ?? '',
    phone:          editing?.phone ?? '',
    address:        '',
    payment_terms:  editing?.payment_terms ?? '',
    lead_time_days: editing?.lead_time_days ?? 7,
    is_active:      editing?.is_active ?? true,
  });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    if (editing) {
      form.put(`/procurement/suppliers/${editing.id}`, { onSuccess: onClose });
    } else {
      form.post('/procurement/suppliers', { onSuccess: () => { onClose(); form.reset(); } });
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader><DialogTitle>{editing ? 'Edit Supplier' : 'New Supplier'}</DialogTitle></DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1"><Label>Name *</Label><Input value={form.data.name} onChange={e => form.setData('name', e.target.value)} required /></div>
            <div className="space-y-1"><Label>Code *</Label><Input value={form.data.code} onChange={e => form.setData('code', e.target.value.toUpperCase())} required maxLength={20} className="font-mono uppercase" /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1"><Label>Contact Person</Label><Input value={form.data.contact_person} onChange={e => form.setData('contact_person', e.target.value)} /></div>
            <div className="space-y-1"><Label>Phone</Label><Input value={form.data.phone} onChange={e => form.setData('phone', e.target.value)} /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1"><Label>Email</Label><Input type="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></div>
            <div className="space-y-1"><Label>Payment Terms</Label><Input value={form.data.payment_terms} onChange={e => form.setData('payment_terms', e.target.value)} placeholder="NET_30, COD..." /></div>
          </div>
          <div className="space-y-1"><Label>Address</Label><Input value={form.data.address} onChange={e => form.setData('address', e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1"><Label>Lead Time (days)</Label><Input type="number" min={0} value={form.data.lead_time_days} onChange={e => form.setData('lead_time_days', Number(e.target.value))} /></div>
            <label className="flex items-center gap-2 pt-6 text-sm">
              <input type="checkbox" checked={form.data.is_active} onChange={e => form.setData('is_active', e.target.checked)} />Active
            </label>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={form.processing}>{editing ? 'Save' : 'Create'}</Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
