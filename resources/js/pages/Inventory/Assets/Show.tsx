import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
import { ArrowLeft, CheckCircle, Edit2, UserPlus, Trash2 } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';

interface User {
  id: number;
  name: string;
}

interface DepScheduleRow {
  id: number;
  year: number;
  fiscal_year: number;
  opening_book_value: number;
  depreciation_amount: number;
  closing_book_value: number;
  depreciation_date: string;
  is_posted: boolean;
  posted_at?: string;
  posted_by?: User;
}

interface Assignment {
  id: number;
  assigned_user: User;
  assigned_by_user: User;
  department?: string;
  location?: string;
  assigned_at: string;
  returned_at?: string;
  notes?: string;
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
  current_book_value: number;
  status: string;
  department?: string;
  warehouse?: { name: string };
  assigned_user?: User;
  created_by?: User;
  uom?: { name: string; abbreviation: string };
  quantity: number;
  disposed_at?: string;
  disposal_reason?: string;
  disposal_value?: number;
  depreciation_schedule: DepScheduleRow[];
  assignments: Assignment[];
}

interface Props {
  asset: CapexAsset;
  users: User[];
}

const STATUS_COLORS: Record<string, string> = {
  ACTIVE: 'bg-success/10 text-success',
  DISPOSED: 'bg-muted text-muted-foreground',
  UNDER_REPAIR: 'bg-warning/10 text-warning',
};

export default function AssetShow({ asset, users }: Props) {
  const [assignOpen, setAssignOpen] = useState(false);
  const [disposeOpen, setDisposeOpen] = useState(false);

  const totalDepreciated = Number(asset.acquisition_cost) - Number(asset.current_book_value);
  const percentDepreciated =
    Number(asset.acquisition_cost) > 0
      ? Math.round((totalDepreciated / Number(asset.acquisition_cost)) * 100)
      : 0;

  return (
    <AppLayout>
      <Head title={`Asset: ${asset.asset_code}`} />
      <div className="space-y-4 p-4 sm:space-y-6 sm:p-6">
        {/* Header */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link
              href="/inventory/assets"
              className="mb-1 flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-3 w-3" /> CAPEX Assets
            </Link>
            <h1 className="text-2xl font-bold">{asset.name}</h1>
            <div className="mt-1 flex items-center gap-2">
              <span className="font-mono text-sm text-muted-foreground">{asset.asset_code}</span>
              <span
                className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLORS[asset.status] ?? ''}`}
              >
                {asset.status.replace('_', ' ')}
              </span>
            </div>
          </div>
          {asset.status === 'ACTIVE' && (
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setAssignOpen(true)}>
                <UserPlus className="mr-1.5 h-3.5 w-3.5" />
                Assign
              </Button>
              <Link href={`/inventory/assets/${asset.id}/edit`}>
                <Button variant="outline" size="sm">
                  <Edit2 className="mr-1.5 h-3.5 w-3.5" />
                  Edit
                </Button>
              </Link>
              <Button
                variant="outline"
                size="sm"
                className="text-destructive border-destructive/20 hover:bg-destructive/5"
                onClick={() => setDisposeOpen(true)}
              >
                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                Dispose
              </Button>
            </div>
          )}
        </div>

        {/* KPIs */}
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          <SummaryCard
            label="Acquisition Cost"
            value={formatCurrency(Number(asset.acquisition_cost))}
          />
          <SummaryCard
            label="Current Book Value"
            value={formatCurrency(Number(asset.current_book_value))}
          />
          <SummaryCard label="Depreciated" value={`${percentDepreciated}%`} />
          <SummaryCard label="Dep. Period" value={`${asset.depreciation_years} Year(s)`} />
        </div>

        {/* Details */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Details</CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm md:grid-cols-3">
            <Detail label="Category" value={asset.category.replace('_', ' ')} />
            <Detail label="Purchase Date" value={formatDate(asset.purchase_date)} />
            <Detail label="Salvage Value" value={formatCurrency(Number(asset.salvage_value))} />
            <Detail label="Warehouse" value={asset.warehouse?.name ?? '—'} />
            <Detail label="Assigned To" value={asset.assigned_user?.name ?? '—'} />
            <Detail label="Department" value={asset.department ?? '—'} />
            <Detail
              label="Quantity"
              value={`${asset.quantity}${asset.uom ? ` ${asset.uom.abbreviation}` : ''}`}
            />
            <Detail label="Created By" value={asset.created_by?.name ?? '—'} />
            {asset.description && (
              <div className="col-span-full">
                <Detail label="Description" value={asset.description} />
              </div>
            )}
          </CardContent>
        </Card>

        {/* Depreciation schedule */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Depreciation Schedule</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Year</TableHead>
                  <TableHead>Fiscal Year</TableHead>
                  <TableHead className="text-right">Opening BV</TableHead>
                  <TableHead className="text-right">Depreciation</TableHead>
                  <TableHead className="text-right">Closing BV</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Posted</TableHead>
                  <TableHead className="w-28"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {asset.depreciation_schedule.map((row) => (
                  <TableRow key={row.id} className={row.is_posted ? 'opacity-60' : ''}>
                    <TableCell className="font-medium">Year {row.year}</TableCell>
                    <TableCell>{row.fiscal_year}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatCurrency(Number(row.opening_book_value))}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-warning">
                      ({formatCurrency(Number(row.depreciation_amount))})
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium">
                      {formatCurrency(Number(row.closing_book_value))}
                    </TableCell>
                    <TableCell className="text-sm whitespace-nowrap">
                      {formatDate(row.depreciation_date)}
                    </TableCell>
                    <TableCell>
                      {row.is_posted ? (
                        <span className="inline-flex items-center gap-1 text-xs text-success">
                          <CheckCircle className="h-3.5 w-3.5" />
                          {formatDate(row.posted_at!)}
                        </span>
                      ) : (
                        <span className="text-xs text-muted-foreground">Pending</span>
                      )}
                    </TableCell>
                    <TableCell>
                      {!row.is_posted && asset.status === 'ACTIVE' && (
                        <PostDepreciationButton scheduleId={row.id} />
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Assignment history */}
        {asset.assignments.length > 0 && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Assignment History</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Assigned To</TableHead>
                    <TableHead>Department / Location</TableHead>
                    <TableHead>Assigned At</TableHead>
                    <TableHead>Returned At</TableHead>
                    <TableHead>Notes</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {asset.assignments.map((a) => (
                    <TableRow key={a.id}>
                      <TableCell className="font-medium text-sm">
                        {a.assigned_user?.name ?? '—'}
                      </TableCell>
                      <TableCell className="text-sm">
                        {a.department ?? '—'}
                        {a.location ? ` · ${a.location}` : ''}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
                        {formatDate(a.assigned_at)}
                      </TableCell>
                      <TableCell className="text-xs whitespace-nowrap">
                        {a.returned_at ? (
                          <span className="text-muted-foreground">{formatDate(a.returned_at)}</span>
                        ) : (
                          <span className="font-medium text-success">Current</span>
                        )}
                      </TableCell>
                      <TableCell className="text-xs text-muted-foreground max-w-xs truncate">
                        {a.notes ?? '—'}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        )}

        {/* Disposal info */}
        {asset.status === 'DISPOSED' && (
          <Card className="border-destructive/20">
            <CardHeader className="pb-2">
              <CardTitle className="text-base text-destructive">Disposal Record</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-3 gap-4 text-sm">
              <Detail
                label="Disposed At"
                value={asset.disposed_at ? formatDate(asset.disposed_at) : '—'}
              />
              <Detail
                label="Disposal Value"
                value={
                  asset.disposal_value != null ? formatCurrency(Number(asset.disposal_value)) : '—'
                }
              />
              <Detail label="Reason" value={asset.disposal_reason ?? '—'} />
            </CardContent>
          </Card>
        )}
      </div>

      <AssignDialog
        open={assignOpen}
        onClose={() => setAssignOpen(false)}
        asset={asset}
        users={users}
      />
      <DisposeDialog open={disposeOpen} onClose={() => setDisposeOpen(false)} asset={asset} />
    </AppLayout>
  );
}

function PostDepreciationButton({ scheduleId }: { scheduleId: number }) {
  const form = useForm({});
  return (
    <Button
      size="sm"
      variant="outline"
      disabled={form.processing}
      onClick={() => form.post(`/inventory/assets/depreciation/${scheduleId}/post`)}
    >
      <CheckCircle className="mr-1 h-3.5 w-3.5" />
      Post
    </Button>
  );
}

function AssignDialog({
  open,
  onClose,
  asset,
  users,
}: {
  open: boolean;
  onClose: () => void;
  asset: CapexAsset;
  users: User[];
}) {
  const form = useForm({ assigned_to: '', department: '', location: '', notes: '' });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post(`/inventory/assets/${asset.id}/assign`, { onSuccess: onClose });
  }

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Assign Asset</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="space-y-1">
            <Label>Assign To *</Label>
            <Select
              value={form.data.assigned_to || 'none'}
              onValueChange={(v) => form.setData('assigned_to', v === 'none' ? '' : v)}
            >
              <SelectTrigger>
                <SelectValue placeholder="Select user..." />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="none">— Select —</SelectItem>
                {users.map((u) => (
                  <SelectItem key={u.id} value={String(u.id)}>
                    {u.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {form.errors.assigned_to && (
              <p className="text-xs text-destructive">{form.errors.assigned_to}</p>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label>Department</Label>
              <Input
                value={form.data.department}
                onChange={(e) => form.setData('department', e.target.value)}
              />
            </div>
            <div className="space-y-1">
              <Label>Location</Label>
              <Input
                value={form.data.location}
                onChange={(e) => form.setData('location', e.target.value)}
              />
            </div>
          </div>
          <div className="space-y-1">
            <Label>Notes</Label>
            <Textarea
              rows={2}
              value={form.data.notes}
              onChange={(e) => form.setData('notes', e.target.value)}
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={form.processing || !form.data.assigned_to}>
              Assign
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function DisposeDialog({
  open,
  onClose,
  asset,
}: {
  open: boolean;
  onClose: () => void;
  asset: CapexAsset;
}) {
  const form = useForm({ disposal_reason: '', disposal_value: '' });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post(`/inventory/assets/${asset.id}/dispose`, { onSuccess: onClose });
  }

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Dispose Asset</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-3">
          <div className="rounded-md border border-destructive/20 bg-destructive/5 p-3 text-sm">
            <div className="font-medium text-destructive">{asset.name}</div>
            <div className="font-mono text-xs text-destructive/70">{asset.asset_code}</div>
          </div>
          <div className="space-y-1">
            <Label>Disposal Reason *</Label>
            <Textarea
              rows={2}
              value={form.data.disposal_reason}
              onChange={(e) => form.setData('disposal_reason', e.target.value)}
              required
            />
            {form.errors.disposal_reason && (
              <p className="text-xs text-destructive">{form.errors.disposal_reason}</p>
            )}
          </div>
          <div className="space-y-1">
            <Label>Disposal / Salvage Value</Label>
            <Input
              type="number"
              min={0}
              step="0.01"
              value={form.data.disposal_value}
              onChange={(e) => form.setData('disposal_value', e.target.value)}
              placeholder="0.00"
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant="destructive"
              disabled={form.processing || !form.data.disposal_reason.trim()}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              Confirm Disposal
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        <p className="mt-1 text-xl font-bold">{value}</p>
      </CardContent>
    </Card>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="mt-0.5">{value}</p>
    </div>
  );
}
