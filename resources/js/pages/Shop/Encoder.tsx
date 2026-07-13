import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Download,
  Eye,
  FileSpreadsheet,
  PackageCheck,
  Truck,
  Archive,
  RotateCcw,
  StickyNote,
  Trash2,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

interface Order {
  id: number;
  order_number: string;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city?: string | null;
  state?: string | null;
  barangay?: string | null;
  total_amount: string | number;
  address_confidence?: string | number | null;
  product?: { id: number; name: string; sku: string } | null;
  shop_items?: { order_id: number; product_name: string; quantity: number }[];
}

interface Batch {
  id: number;
  batch_number: string;
  courier_code: string;
  region?: string | null;
  status: string;
  row_count: number;
  failed_row_count?: number;
  file_path?: string | null;
  exported_at?: string | null;
  downloaded_at?: string | null;
  archived_at?: string | null;
  notes?: string | null;
  created_by?: number | null;
  creator?: { id: number; name: string } | null;
  created_at: string;
}

interface Paginated<T> {
  data: T[];
}

interface Props {
  orders: Paginated<Order>;
  recent_batches: Batch[];
  couriers: { value: string; label: string }[];
}

function money(value: string | number) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(
    Number(value)
  );
}

function orderSummary(order: Order) {
  if (order.shop_items && order.shop_items.length > 0) {
    return order.shop_items.map((item) => `${item.product_name} x${item.quantity}`).join(', ');
  }

  return order.product?.name ?? 'No product';
}

function AddressEditor({ order }: { order: Order }) {
  const [form, setForm] = useState({
    receiver_address: order.receiver_address ?? '',
    barangay: order.barangay ?? '',
    city: order.city ?? '',
    state: order.state ?? '',
    notes: '',
  });

  const update = (key: keyof typeof form, value: string) =>
    setForm((current) => ({ ...current, [key]: value }));

  return (
    <div className="space-y-3 border-t pt-3">
      <Textarea
        value={form.receiver_address}
        onChange={(event) => update('receiver_address', event.target.value)}
        placeholder="Complete address"
      />
      <div className="grid gap-2 md:grid-cols-3">
        <Input
          value={form.barangay}
          onChange={(event) => update('barangay', event.target.value)}
          placeholder="Barangay"
        />
        <Input
          value={form.city}
          onChange={(event) => update('city', event.target.value)}
          placeholder="City / Municipality"
        />
        <Input
          value={form.state}
          onChange={(event) => update('state', event.target.value)}
          placeholder="Province"
        />
      </div>
      <Input
        value={form.notes}
        onChange={(event) => update('notes', event.target.value)}
        placeholder="Encoder remarks"
      />
      <div className="flex flex-wrap gap-2">
        <Button
          size="sm"
          variant="outline"
          onClick={() =>
            router.patch(`/shop/encoder/orders/${order.id}/address`, form, { preserveScroll: true })
          }
        >
          Save Address
        </Button>
        <Button
          size="sm"
          onClick={() =>
            router.post(`/shop/encoder/orders/${order.id}/encoded`, {}, { preserveScroll: true })
          }
        >
          Mark Encoded
        </Button>
      </div>
    </div>
  );
}

export default function ShopEncoder({ orders, recent_batches, couriers }: Props) {
  const [selectedOrderIds, setSelectedOrderIds] = useState<number[]>([]);
  const [groupByRegion, setGroupByRegion] = useState(false);
  const [editingNotesId, setEditingNotesId] = useState<number | null>(null);
  const [notesDraft, setNotesDraft] = useState('');
  const [previewBatch, setPreviewBatch] = useState<{
    id: number;
    batch_number: string;
    courier_code: string;
    region?: string | null;
    status: string;
    row_count: number;
  } | null>(null);
  const [previewRows, setPreviewRows] = useState<
    {
      id: number;
      row_number: number;
      status: string;
      receiver_name: string;
      phone_number: string;
      complete_address: string;
      province?: string | null;
      city?: string | null;
      barangay?: string | null;
      product_name: string;
      cod_amount: string;
      quantity: number;
      remarks?: string | null;
      error_message?: string | null;
    }[]
  >([]);
  const [previewLoading, setPreviewLoading] = useState(false);

  const toggleOrder = (orderId: number) => {
    setSelectedOrderIds((current) =>
      current.includes(orderId) ? current.filter((id) => id !== orderId) : [...current, orderId]
    );
  };

  const toggleAll = () => {
    setSelectedOrderIds((current) =>
      current.length === orders.data.length ? [] : orders.data.map((order) => order.id)
    );
  };

  const saveNotes = (batchId: number) => {
    router.patch(`/shop/exports/${batchId}/notes`, { notes: notesDraft }, { preserveScroll: true });
    setEditingNotesId(null);
  };

  const openPreview = (batchId: number) => {
    setPreviewLoading(true);
    setPreviewBatch(null);
    setPreviewRows([]);
    fetch(`/shop/exports/${batchId}/preview`, {
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => {
        setPreviewBatch(data.batch);
        setPreviewRows(data.rows);
      })
      .finally(() => setPreviewLoading(false));
  };

  const exportCourier = (courierCode: string) => {
    router.post('/shop/exports', {
      courier_code: courierCode,
      order_ids: selectedOrderIds.length > 0 ? selectedOrderIds : undefined,
      group_by_region: groupByRegion || undefined,
    });
  };

  return (
    <AppLayout>
      <Head title="Shop Encoder" />

      <div className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Shop Encoder</h1>
            <p className="text-muted-foreground">
              Confirmed orders ready for address review and courier export
            </p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {orders.data.length > 0 && (
              <Button variant="outline" onClick={toggleAll}>
                {selectedOrderIds.length === orders.data.length ? 'Clear Selection' : 'Select All'}
              </Button>
            )}
            <label className="flex items-center gap-1.5 text-sm text-muted-foreground">
              <input
                type="checkbox"
                checked={groupByRegion}
                onChange={(e) => setGroupByRegion(e.target.checked)}
                className="h-4 w-4"
              />
              Group by Region
            </label>
            {couriers.map((courier) => (
              <Button
                key={courier.value}
                variant={courier.value === 'FLASH' ? 'default' : 'outline'}
                onClick={() => exportCourier(courier.value)}
              >
                {courier.value === 'JNT' ? (
                  <Truck className="mr-1.5 h-4 w-4" />
                ) : (
                  <FileSpreadsheet className="mr-1.5 h-4 w-4" />
                )}
                Export {courier.label}
              </Button>
            ))}
          </div>
        </div>

        {orders.data.length > 0 && (
          <div className="rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
            {selectedOrderIds.length > 0
              ? `${selectedOrderIds.length} selected for the next export.`
              : 'No orders selected. Export buttons will include all encoder-ready orders.'}
          </div>
        )}

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-3 xl:col-span-2">
            {orders.data.length === 0 ? (
              <Card>
                <CardContent className="py-16 text-center text-muted-foreground">
                  <PackageCheck className="mx-auto mb-3 h-10 w-10 opacity-30" />
                  <p className="font-medium">No orders waiting for encoding</p>
                  <p className="text-sm">
                    Confirmed Shop orders will appear here before courier export.
                  </p>
                </CardContent>
              </Card>
            ) : (
              orders.data.map((order) => (
                <Card key={order.id}>
                  <CardHeader className="pb-3">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                      <div className="flex items-start gap-3">
                        <input
                          type="checkbox"
                          className="mt-1 h-4 w-4 rounded border-muted-foreground/40"
                          checked={selectedOrderIds.includes(order.id)}
                          onChange={() => toggleOrder(order.id)}
                          aria-label={`Select ${order.order_number} for export`}
                        />
                        <div>
                          <CardTitle className="text-base">{order.order_number}</CardTitle>
                          <CardDescription>
                            {order.receiver_name} - {order.receiver_phone}
                          </CardDescription>
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">{money(order.total_amount)}</Badge>
                        <Badge variant="secondary">
                          {Number(order.address_confidence ?? 0)}% address
                        </Badge>
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-2 text-sm">
                    <p>{order.receiver_address}</p>
                    <p className="text-muted-foreground">
                      {[order.barangay, order.city, order.state].filter(Boolean).join(', ') ||
                        'No structured location'}
                    </p>
                    <p className="text-muted-foreground">{orderSummary(order)}</p>
                    <AddressEditor order={order} />
                  </CardContent>
                </Card>
              ))
            )}
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Recent Export Batches</CardTitle>
              <CardDescription>Generated courier CSV files</CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              {recent_batches.length === 0 ? (
                <p className="text-sm text-muted-foreground">No export batches yet.</p>
              ) : (
                recent_batches.map((batch) => (
                  <div key={batch.id} className="rounded-lg border p-3">
                    <div className="flex items-center justify-between gap-3">
                      <div className="space-y-1">
                        <div className="flex items-center gap-2">
                          <p className="text-sm font-medium">{batch.batch_number}</p>
                          <Badge
                            variant={
                              batch.status === 'ready'
                                ? 'default'
                                : batch.status === 'downloaded'
                                  ? 'secondary'
                                  : batch.status === 'archived'
                                    ? 'outline'
                                    : 'destructive'
                            }
                            className="text-[10px]"
                          >
                            {batch.status}
                          </Badge>
                        </div>
                        <p className="text-xs text-muted-foreground">
                          {batch.courier_code}
                          {batch.region && <span className="font-medium"> - {batch.region}</span>}
                          {' - '}
                          {batch.row_count} rows
                          {batch.failed_row_count && batch.failed_row_count > 0 && (
                            <span className="ml-1 text-destructive">
                              ({batch.failed_row_count} failed)
                            </span>
                          )}
                          {batch.creator && (
                            <span className="ml-1 text-muted-foreground/70">
                              by {batch.creator.name}
                            </span>
                          )}
                        </p>
                        {editingNotesId === batch.id ? (
                          <div className="mt-2 flex items-start gap-2">
                            <Textarea
                              value={notesDraft}
                              onChange={(e) => setNotesDraft(e.target.value)}
                              placeholder="Add notes for this batch..."
                              className="min-h-[60px] text-xs"
                              rows={2}
                            />
                            <div className="flex flex-col gap-1">
                              <Button type="button" size="sm" onClick={() => saveNotes(batch.id)}>
                                Save
                              </Button>
                              <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setEditingNotesId(null)}
                              >
                                Cancel
                              </Button>
                            </div>
                          </div>
                        ) : (
                          <div className="mt-1 flex items-center gap-2">
                            {batch.notes && (
                              <p className="line-clamp-2 text-xs italic text-muted-foreground">
                                {batch.notes}
                              </p>
                            )}
                            <button
                              type="button"
                              onClick={() => {
                                setEditingNotesId(batch.id);
                                setNotesDraft(batch.notes ?? '');
                              }}
                              className="shrink-0 text-muted-foreground/60 hover:text-foreground"
                            >
                              <StickyNote className="h-3 w-3" />
                            </button>
                          </div>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        {batch.file_path && batch.status !== 'archived' && (
                          <Button asChild size="sm" variant="outline">
                            <Link href={`/shop/exports/${batch.id}/download`}>
                              <Download className="h-4 w-4" />
                            </Link>
                          </Button>
                        )}
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => openPreview(batch.id)}
                        >
                          <Eye className="h-4 w-4" />
                        </Button>
                        {batch.failed_row_count &&
                          batch.failed_row_count > 0 &&
                          batch.status !== 'archived' && (
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              onClick={() =>
                                router.post(
                                  `/shop/exports/${batch.id}/retry`,
                                  {},
                                  { preserveScroll: true }
                                )
                              }
                            >
                              <RotateCcw className="h-4 w-4" />
                            </Button>
                          )}
                        {(batch.status === 'ready' || batch.status === 'downloaded') && (
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                              router.post(
                                `/shop/exports/${batch.id}/archive`,
                                {},
                                { preserveScroll: true }
                              )
                            }
                          >
                            <Archive className="h-4 w-4" />
                          </Button>
                        )}
                        {batch.status === 'archived' && (
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                              if (
                                confirm(
                                  `Delete batch ${batch.batch_number}? This cannot be undone.`
                                )
                              ) {
                                router.delete(`/shop/exports/${batch.id}`, {
                                  preserveScroll: true,
                                });
                              }
                            }}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        )}
                      </div>
                    </div>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {previewBatch && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
          onClick={() => setPreviewBatch(null)}
        >
          <div
            className="max-h-[80vh] w-full max-w-4xl overflow-hidden rounded-lg bg-background shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between border-b px-4 py-3">
              <div>
                <p className="text-sm font-medium">{previewBatch.batch_number}</p>
                <p className="text-xs text-muted-foreground">
                  {previewBatch.courier_code}
                  {previewBatch.region && ` - ${previewBatch.region}`}
                  {' - '}
                  {previewBatch.row_count} rows
                </p>
              </div>
              <button
                type="button"
                onClick={() => setPreviewBatch(null)}
                className="text-muted-foreground hover:text-foreground"
              >
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="max-h-[60vh] overflow-auto">
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-muted/50">
                  <tr>
                    <th className="px-2 py-1.5 text-left font-medium">#</th>
                    <th className="px-2 py-1.5 text-left font-medium">Status</th>
                    <th className="px-2 py-1.5 text-left font-medium">Receiver</th>
                    <th className="px-2 py-1.5 text-left font-medium">Phone</th>
                    <th className="px-2 py-1.5 text-left font-medium">Address</th>
                    <th className="px-2 py-1.5 text-left font-medium">Product</th>
                    <th className="px-2 py-1.5 text-right font-medium">COD</th>
                  </tr>
                </thead>
                <tbody>
                  {previewRows.map((row) => (
                    <tr key={row.id} className="border-t">
                      <td className="px-2 py-1.5 text-muted-foreground">{row.row_number}</td>
                      <td className="px-2 py-1.5">
                        <span
                          className={
                            row.status === 'exported'
                              ? 'text-green-600'
                              : row.status === 'failed'
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                          }
                        >
                          {row.status}
                        </span>
                      </td>
                      <td className="px-2 py-1.5">{row.receiver_name}</td>
                      <td className="px-2 py-1.5">{row.phone_number}</td>
                      <td
                        className="max-w-[200px] truncate px-2 py-1.5"
                        title={row.complete_address}
                      >
                        {row.complete_address}
                      </td>
                      <td className="px-2 py-1.5">{row.product_name}</td>
                      <td className="px-2 py-1.5 text-right">{row.cod_amount}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex items-center justify-end gap-2 border-t px-4 py-3">
              {previewBatch.status !== 'archived' && (
                <Button asChild size="sm">
                  <Link href={`/shop/exports/${previewBatch.id}/download`}>
                    <Download className="mr-1.5 h-4 w-4" />
                    Download CSV
                  </Link>
                </Button>
              )}
            </div>
          </div>
        </div>
      )}

      {previewLoading && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <p className="text-sm text-background">Loading preview...</p>
        </div>
      )}
    </AppLayout>
  );
}
