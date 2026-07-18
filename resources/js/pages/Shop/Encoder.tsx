import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  BarChart3,
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
  AlertTriangle,
  Filter,
  ClipboardCheck,
  History,
  Upload,
  MapPin,
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
  landmark?: string | null;
  nearest_landmark?: string | null;
  latitude?: string | number | null;
  longitude?: string | number | null;
  total_amount: string | number;
  address_confidence?: string | number | null;
  address_flags?: string[];
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
  filters?: { needs_review?: boolean };
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

function AddressEditor({ order, hasFlags }: { order: Order; hasFlags: boolean }) {
  const [form, setForm] = useState({
    receiver_address: order.receiver_address ?? '',
    barangay: order.barangay ?? '',
    city: order.city ?? '',
    state: order.state ?? '',
    landmark: order.landmark ?? '',
    nearest_landmark: order.nearest_landmark ?? '',
    notes: '',
  });
  const [validation, setValidation] = useState<{
    province: { valid: boolean; suggestions: string[] };
    city_municipality: { valid: boolean; suggestions: string[] };
    barangay: { valid: boolean; suggestions: string[] };
    overall_valid: boolean;
    confidence: {
      total: number;
      components: Record<string, number>;
      matched_components: string[];
    };
  } | null>(null);
  const [validateTimer, setValidateTimer] = useState<ReturnType<typeof setTimeout> | null>(null);
  const [autocomplete, setAutocomplete] = useState<{
    field: 'province' | 'city_municipality' | 'barangay' | null;
    items: string[];
  }>({ field: null, items: [] });
  const [autocompleteTimer, setAutocompleteTimer] = useState<ReturnType<typeof setTimeout> | null>(
    null
  );
  const [showHistory, setShowHistory] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyEntries, setHistoryEntries] = useState<
    {
      id: number;
      user: string;
      before: Record<string, string | null>;
      after: Record<string, string | null>;
      confidence_before: number;
      confidence_after: number;
      action: string;
      created_at: string;
    }[]
  >([]);
  const [geocodeLoading, setGeocodeLoading] = useState(false);
  const [geocodeResult, setGeocodeResult] = useState<{
    success: boolean;
    latitude: number;
    longitude: number;
    display_name: string;
    suggestions: Record<string, string>;
  } | null>(null);

  const fetchAutocomplete = (
    field: 'province' | 'city_municipality' | 'barangay',
    q: string,
    currentForm: typeof form
  ) => {
    if (q.trim().length < 2) {
      setAutocomplete({ field: null, items: [] });
      return;
    }
    const params = new URLSearchParams({ field, q });
    if (field !== 'province' && currentForm.state) params.append('province', currentForm.state);
    if (field === 'barangay' && currentForm.city)
      params.append('city_municipality', currentForm.city);
    fetch(`/shop/encoder/autocomplete?${params.toString()}`)
      .then((res) => res.json())
      .then((data) => setAutocomplete({ field, items: data }))
      .catch(() => setAutocomplete({ field: null, items: [] }));
  };

  const loadHistory = () => {
    if (showHistory) {
      setShowHistory(false);
      return;
    }
    setHistoryLoading(true);
    fetch(`/shop/encoder/orders/${order.id}/correction-history`, {
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => {
        setHistoryEntries(data.history ?? []);
        setShowHistory(true);
      })
      .finally(() => setHistoryLoading(false));
  };

  const applyGeocodeSuggestion = (field: string, value: string) => {
    if (field === 'province') update('state', value);
    else if (field === 'city_municipality') update('city', value);
    else if (field === 'barangay') update('barangay', value);
  };

  const runGeocode = () => {
    setGeocodeLoading(true);
    setGeocodeResult(null);
    fetch(`/shop/encoder/orders/${order.id}/geocode`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        Accept: 'application/json',
      },
    })
      .then((res) => res.json())
      .then((data) => setGeocodeResult(data))
      .catch(() =>
        setGeocodeResult({
          success: false,
          latitude: 0,
          longitude: 0,
          display_name: '',
          suggestions: {},
          message: 'Geocoding failed',
        } as typeof geocodeResult & { message?: string })
      )
      .finally(() => setGeocodeLoading(false));
  };

  const update = (key: keyof typeof form, value: string) => {
    setForm((current) => ({ ...current, [key]: value }));
    if (validateTimer) clearTimeout(validateTimer);
    const timer = setTimeout(() => {
      const params = new URLSearchParams();
      if (form.state) params.append('province', form.state);
      if (form.city) params.append('city_municipality', form.city);
      if (form.barangay) params.append('barangay', form.barangay);
      if (key === 'state') params.set('province', value);
      if (key === 'city') params.set('city_municipality', value);
      if (key === 'barangay') params.set('barangay', value);
      fetch('/shop/encoder/validate-address', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
        },
        body: JSON.stringify({
          province: key === 'state' ? value : form.state,
          city_municipality: key === 'city' ? value : form.city,
          barangay: key === 'barangay' ? value : form.barangay,
        }),
      })
        .then((res) => res.json())
        .then((data) => setValidation(data))
        .catch(() => setValidation(null));
    }, 400);
    setValidateTimer(timer);

    if (autocompleteTimer) clearTimeout(autocompleteTimer);
    const acField =
      key === 'state'
        ? 'province'
        : key === 'city'
          ? 'city_municipality'
          : key === 'barangay'
            ? 'barangay'
            : null;
    if (acField) {
      const acTimer = setTimeout(() => {
        fetchAutocomplete(acField, value, form);
      }, 300);
      setAutocompleteTimer(acTimer);
    }
  };

  const selectSuggestion = (
    field: 'province' | 'city_municipality' | 'barangay',
    value: string
  ) => {
    setAutocomplete({ field: null, items: [] });
    if (field === 'province') update('state', value);
    else if (field === 'city_municipality') update('city', value);
    else update('barangay', value);
  };

  const fieldIcon = (valid: boolean, hasValue: boolean) => {
    if (!hasValue) return null;
    return valid ? (
      <span className="text-green-600 text-xs">✓</span>
    ) : (
      <span className="text-destructive text-xs">✗</span>
    );
  };

  return (
    <div className="space-y-3 border-t pt-3">
      <Textarea
        value={form.receiver_address}
        onChange={(event) => update('receiver_address', event.target.value)}
        placeholder="Complete address"
      />
      <div className="grid gap-2 md:grid-cols-3">
        <div className="space-y-1">
          <div className="flex items-center gap-1.5">
            <Input
              value={form.barangay}
              onChange={(event) => update('barangay', event.target.value)}
              placeholder="Barangay"
              className={
                validation && form.barangay
                  ? validation.barangay.valid
                    ? 'border-green-500'
                    : 'border-destructive'
                  : ''
              }
            />
            {validation && fieldIcon(validation.barangay.valid, Boolean(form.barangay))}
          </div>
          {autocomplete.field === 'barangay' && autocomplete.items.length > 0 && (
            <div className="absolute z-10 mt-1 max-h-40 overflow-auto rounded-md border bg-popover shadow-md">
              {autocomplete.items.map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => selectSuggestion('barangay', s)}
                  className="block w-full px-2 py-1 text-left text-xs hover:bg-accent"
                >
                  {s}
                </button>
              ))}
            </div>
          )}
          {validation &&
            !validation.barangay.valid &&
            validation.barangay.suggestions.length > 0 && (
              <div className="space-y-1">
                <span className="text-xs text-muted-foreground">Did you mean?</span>
                <div className="flex flex-wrap gap-1">
                  {validation.barangay.suggestions.map((s) => (
                    <button
                      key={s}
                      type="button"
                      onClick={() => update('barangay', s)}
                      className="rounded bg-muted px-1.5 py-0.5 text-xs hover:bg-accent"
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}
        </div>
        <div className="space-y-1">
          <div className="flex items-center gap-1.5">
            <Input
              value={form.city}
              onChange={(event) => update('city', event.target.value)}
              placeholder="City / Municipality"
              className={
                validation && form.city
                  ? validation.city_municipality.valid
                    ? 'border-green-500'
                    : 'border-destructive'
                  : ''
              }
            />
            {validation && fieldIcon(validation.city_municipality.valid, Boolean(form.city))}
          </div>
          {autocomplete.field === 'city_municipality' && autocomplete.items.length > 0 && (
            <div className="absolute z-10 mt-1 max-h-40 overflow-auto rounded-md border bg-popover shadow-md">
              {autocomplete.items.map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => selectSuggestion('city_municipality', s)}
                  className="block w-full px-2 py-1 text-left text-xs hover:bg-accent"
                >
                  {s}
                </button>
              ))}
            </div>
          )}
          {validation &&
            !validation.city_municipality.valid &&
            validation.city_municipality.suggestions.length > 0 && (
              <div className="space-y-1">
                <span className="text-xs text-muted-foreground">Did you mean?</span>
                <div className="flex flex-wrap gap-1">
                  {validation.city_municipality.suggestions.map((s) => (
                    <button
                      key={s}
                      type="button"
                      onClick={() => update('city', s)}
                      className="rounded bg-muted px-1.5 py-0.5 text-xs hover:bg-accent"
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}
        </div>
        <div className="space-y-1">
          <div className="flex items-center gap-1.5">
            <Input
              value={form.state}
              onChange={(event) => update('state', event.target.value)}
              placeholder="Province"
              className={
                validation && form.state
                  ? validation.province.valid
                    ? 'border-green-500'
                    : 'border-destructive'
                  : ''
              }
            />
            {validation && fieldIcon(validation.province.valid, Boolean(form.state))}
          </div>
          {autocomplete.field === 'province' && autocomplete.items.length > 0 && (
            <div className="absolute z-10 mt-1 max-h-40 overflow-auto rounded-md border bg-popover shadow-md">
              {autocomplete.items.map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => selectSuggestion('province', s)}
                  className="block w-full px-2 py-1 text-left text-xs hover:bg-accent"
                >
                  {s}
                </button>
              ))}
            </div>
          )}
          {validation &&
            !validation.province.valid &&
            validation.province.suggestions.length > 0 && (
              <div className="space-y-1">
                <span className="text-xs text-muted-foreground">Did you mean?</span>
                <div className="flex flex-wrap gap-1">
                  {validation.province.suggestions.map((s) => (
                    <button
                      key={s}
                      type="button"
                      onClick={() => update('state', s)}
                      className="rounded bg-muted px-1.5 py-0.5 text-xs hover:bg-accent"
                    >
                      {s}
                    </button>
                  ))}
                </div>
              </div>
            )}
        </div>
      </div>
      <div className="grid gap-2 md:grid-cols-2">
        <Input
          value={form.landmark}
          onChange={(event) => update('landmark', event.target.value)}
          placeholder="Landmark"
        />
        <Input
          value={form.nearest_landmark}
          onChange={(event) => update('nearest_landmark', event.target.value)}
          placeholder="Nearest landmark"
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
          disabled={hasFlags}
          onClick={() =>
            router.post(`/shop/encoder/orders/${order.id}/encoded`, {}, { preserveScroll: true })
          }
        >
          Mark Encoded
        </Button>
        <Button size="sm" variant="ghost" onClick={loadHistory} disabled={historyLoading}>
          <History className="mr-1.5 h-3.5 w-3.5" />
          {historyLoading ? 'Loading...' : showHistory ? 'Hide History' : 'History'}
        </Button>
        <Button size="sm" variant="ghost" onClick={runGeocode} disabled={geocodeLoading}>
          <MapPin className="mr-1.5 h-3.5 w-3.5" />
          {geocodeLoading ? 'Geocoding...' : 'Geocode'}
        </Button>
        {hasFlags && (
          <span className="text-xs text-destructive">Resolve address issues before encoding</span>
        )}
        {validation && (
          <Badge variant={validation.overall_valid ? 'default' : 'destructive'} className="text-xs">
            {validation.overall_valid ? 'Address Valid' : 'Needs Review'}
          </Badge>
        )}
      </div>
      {validation?.confidence && (
        <div className="space-y-1.5">
          <div className="flex items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground">Confidence Score</span>
            <span
              className={`text-sm font-bold ${
                validation.confidence.total >= 85
                  ? 'text-green-600'
                  : validation.confidence.total >= 50
                    ? 'text-yellow-600'
                    : 'text-destructive'
              }`}
            >
              {validation.confidence.total}%
            </span>
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
              <div
                className={`h-full rounded-full transition-all ${
                  validation.confidence.total >= 85
                    ? 'bg-green-500'
                    : validation.confidence.total >= 50
                      ? 'bg-yellow-500'
                      : 'bg-destructive'
                }`}
                style={{ width: `${validation.confidence.total}%` }}
              />
            </div>
          </div>
          <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
            {Object.entries(validation.confidence.components).map(([key, score]) => (
              <span key={key} className={score > 0 ? 'text-green-600' : ''}>
                {key.replace(/_/g, ' ')}: {score}
              </span>
            ))}
          </div>
        </div>
      )}
      {showHistory && (
        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">Correction History</span>
            <button onClick={() => setShowHistory(false)}>
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
          {historyEntries.length === 0 ? (
            <p className="text-xs text-muted-foreground">No corrections recorded.</p>
          ) : (
            <div className="max-h-48 space-y-2 overflow-auto">
              {historyEntries.map((entry) => (
                <div key={entry.id} className="rounded border p-2 text-xs">
                  <div className="mb-1 flex items-center justify-between">
                    <span className="font-medium">{entry.user}</span>
                    <span className="text-muted-foreground">
                      {new Date(entry.created_at).toLocaleString()}
                    </span>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <span className="text-muted-foreground">
                        Before ({entry.confidence_before}%):
                      </span>
                      <p className="truncate text-destructive">
                        {entry.before.receiver_address ?? '—'}
                      </p>
                      <p className="truncate text-destructive">
                        {entry.before.barangay ?? '—'}, {entry.before.city ?? '—'},{' '}
                        {entry.before.state ?? '—'}
                      </p>
                    </div>
                    <div>
                      <span className="text-muted-foreground">
                        After ({entry.confidence_after}%):
                      </span>
                      <p className="truncate text-green-600">
                        {entry.after.receiver_address ?? '—'}
                      </p>
                      <p className="truncate text-green-600">
                        {entry.after.barangay ?? '—'}, {entry.after.city ?? '—'},{' '}
                        {entry.after.state ?? '—'}
                      </p>
                    </div>
                  </div>
                  {entry.confidence_after > entry.confidence_before && (
                    <span className="mt-1 inline-block text-green-600">
                      +{(entry.confidence_after - entry.confidence_before).toFixed(1)}% confidence
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}
      {geocodeResult && (
        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">Geocoding Result</span>
            <button onClick={() => setGeocodeResult(null)}>
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
          {geocodeResult.success ? (
            <>
              <div className="flex items-center gap-2 text-xs">
                <MapPin className="h-3.5 w-3.5 text-green-600" />
                <span className="font-mono text-green-600">
                  {geocodeResult.latitude.toFixed(5)}, {geocodeResult.longitude.toFixed(5)}
                </span>
              </div>
              {geocodeResult.display_name && (
                <p
                  className="truncate text-xs text-muted-foreground"
                  title={geocodeResult.display_name}
                >
                  {geocodeResult.display_name}
                </p>
              )}
              {Object.keys(geocodeResult.suggestions).length > 0 && (
                <div className="space-y-1">
                  <span className="text-xs text-muted-foreground">
                    Suggested fields from geocoding:
                  </span>
                  {Object.entries(geocodeResult.suggestions).map(([field, value]) => (
                    <div key={field} className="flex items-center gap-2 text-xs">
                      <span className="text-muted-foreground">{field.replace(/_/g, ' ')}:</span>
                      <span className="font-medium">{value}</span>
                      <button
                        className="text-blue-600 hover:underline"
                        onClick={() => applyGeocodeSuggestion(field, value)}
                      >
                        Apply
                      </button>
                    </div>
                  ))}
                </div>
              )}
              {order.latitude && (
                <p className="text-xs text-muted-foreground">Coordinates saved to order.</p>
              )}
            </>
          ) : (
            <p className="text-xs text-destructive">
              {(geocodeResult as typeof geocodeResult & { message?: string }).message ??
                'Geocoding failed.'}
            </p>
          )}
        </div>
      )}
    </div>
  );
}

export default function ShopEncoder({ orders, recent_batches, couriers, filters }: Props) {
  const [selectedOrderIds, setSelectedOrderIds] = useState<number[]>([]);
  const [selectedCouriers, setSelectedCouriers] = useState<string[]>([]);
  const [groupByRegion, setGroupByRegion] = useState(false);
  const [needsReview, setNeedsReview] = useState(filters?.needs_review ?? false);
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
  const [showAnalytics, setShowAnalytics] = useState(false);
  const [analytics, setAnalytics] = useState<{
    per_batch: {
      id: number;
      batch_number: string;
      courier_code: string;
      region?: string | null;
      status: string;
      total_rows: number;
      exported_rows: number;
      failed_rows: number;
      success_rate: number;
      created_at: string;
    }[];
    summary: {
      total_batches: number;
      total_rows: number;
      total_exported: number;
      total_failed: number;
      overall_success_rate: number;
    };
    by_courier: {
      courier: string;
      batch_count: number;
      total_rows: number;
      exported_rows: number;
      failed_rows: number;
      success_rate: number;
    }[];
  } | null>(null);
  const [analyticsLoading, setAnalyticsLoading] = useState(false);
  const [validationReport, setValidationReport] = useState<{
    total_orders: number;
    valid_orders: number;
    orders_with_issues: number;
    issue_summary: Record<string, number>;
    orders: {
      id: number;
      order_number: string;
      receiver_name: string;
      issues: string[];
      address_confidence: number;
    }[];
  } | null>(null);
  const [showValidationReport, setShowValidationReport] = useState(false);
  const [validationReportLoading, setValidationReportLoading] = useState(false);
  const [pendingExport, setPendingExport] = useState<{
    type: 'single' | 'multi';
    courierCode?: string;
  } | null>(null);
  const [bulkUploadLoading, setBulkUploadLoading] = useState(false);
  const [bulkUploadResult, setBulkUploadResult] = useState<{
    updated: number;
    skipped: number;
    errors: string[];
  } | null>(null);

  const handleBulkUpload = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;
    setBulkUploadLoading(true);
    setBulkUploadResult(null);
    const formData = new FormData();
    formData.append('file', file);
    fetch('/shop/encoder/bulk-address-update', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: formData,
    })
      .then((res) => res.json())
      .then((data) => {
        if (data.error) {
          setBulkUploadResult({ updated: 0, skipped: 0, errors: [data.error] });
        } else {
          setBulkUploadResult(data);
          if (data.updated > 0) {
            router.reload();
          }
        }
      })
      .catch(() => setBulkUploadResult({ updated: 0, skipped: 0, errors: ['Upload failed.'] }))
      .finally(() => {
        setBulkUploadLoading(false);
        event.target.value = '';
      });
  };

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

  const loadAnalytics = () => {
    if (analytics) {
      setShowAnalytics((v) => !v);
      return;
    }
    setAnalyticsLoading(true);
    fetch('/shop/exports/analytics', { headers: { Accept: 'application/json' } })
      .then((res) => res.json())
      .then((data) => {
        setAnalytics(data);
        setShowAnalytics(true);
      })
      .finally(() => setAnalyticsLoading(false));
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

  const loadValidationReport = () => {
    setValidationReportLoading(true);
    fetch('/shop/encoder/validation-report', { headers: { Accept: 'application/json' } })
      .then((res) => res.json())
      .then((data) => {
        setValidationReport(data);
        setShowValidationReport(true);
      })
      .finally(() => setValidationReportLoading(false));
  };

  const exportCourier = (courierCode: string) => {
    setPendingExport({ type: 'single', courierCode });
    setValidationReportLoading(true);
    fetch('/shop/encoder/validation-report', { headers: { Accept: 'application/json' } })
      .then((res) => res.json())
      .then((data) => {
        setValidationReport(data);
        if (data.orders_with_issues > 0) {
          setShowValidationReport(true);
          setValidationReportLoading(false);
        } else {
          proceedExport();
        }
      })
      .catch(() => {
        proceedExport();
      })
      .finally(() => setValidationReportLoading(false));
  };

  const proceedExport = () => {
    setShowValidationReport(false);
    if (!pendingExport) return;
    if (pendingExport.type === 'single' && pendingExport.courierCode) {
      router.post('/shop/exports', {
        courier_code: pendingExport.courierCode,
        order_ids: selectedOrderIds.length > 0 ? selectedOrderIds : undefined,
        group_by_region: groupByRegion || undefined,
      });
    } else if (pendingExport.type === 'multi') {
      router.post('/shop/exports/multi', {
        courier_codes: selectedCouriers,
        order_ids: selectedOrderIds.length > 0 ? selectedOrderIds : undefined,
      });
    }
    setPendingExport(null);
  };

  const toggleCourier = (courierCode: string) => {
    setSelectedCouriers((current) =>
      current.includes(courierCode)
        ? current.filter((code) => code !== courierCode)
        : [...current, courierCode]
    );
  };

  const exportSelectedCouriers = () => {
    if (selectedCouriers.length === 0) return;
    setPendingExport({ type: 'multi' });
    setValidationReportLoading(true);
    fetch('/shop/encoder/validation-report', { headers: { Accept: 'application/json' } })
      .then((res) => res.json())
      .then((data) => {
        setValidationReport(data);
        if (data.orders_with_issues > 0) {
          setShowValidationReport(true);
          setValidationReportLoading(false);
        } else {
          proceedExport();
        }
      })
      .catch(() => {
        proceedExport();
      })
      .finally(() => setValidationReportLoading(false));
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
            <Button
              variant={needsReview ? 'default' : 'outline'}
              size="sm"
              onClick={() => {
                setNeedsReview((v) => !v);
                router.get(
                  '/shop/encoder',
                  { needs_review: !needsReview ? 1 : undefined },
                  { preserveScroll: true }
                );
              }}
            >
              <Filter className="mr-1.5 h-3.5 w-3.5" />
              Needs Review
            </Button>
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
            <Button
              variant="outline"
              size="sm"
              onClick={loadValidationReport}
              disabled={validationReportLoading}
            >
              <ClipboardCheck className="mr-1.5 h-3.5 w-3.5" />
              {validationReportLoading ? 'Checking...' : 'Validation Report'}
            </Button>
            <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-input bg-background px-3 py-1.5 text-sm font-medium ring-offset-background hover:bg-accent hover:text-accent-foreground">
              <Upload className="h-3.5 w-3.5" />
              {bulkUploadLoading ? 'Uploading...' : 'Bulk CSV'}
              <input
                type="file"
                accept=".csv,.txt"
                className="hidden"
                disabled={bulkUploadLoading}
                onChange={handleBulkUpload}
              />
            </label>
            {bulkUploadResult && (
              <div className="text-xs">
                <span className="text-green-600">{bulkUploadResult.updated} updated</span>
                {' / '}
                <span className="text-muted-foreground">{bulkUploadResult.skipped} skipped</span>
                {bulkUploadResult.errors.length > 0 && (
                  <span className="text-destructive">
                    {' '}
                    / {bulkUploadResult.errors.length} errors
                  </span>
                )}
                {bulkUploadResult.errors.length > 0 && (
                  <div
                    className="mt-1 max-w-md truncate text-destructive"
                    title={bulkUploadResult.errors.join('\n')}
                  >
                    {bulkUploadResult.errors.slice(0, 3).join(', ')}
                    {bulkUploadResult.errors.length > 3 &&
                      ` +${bulkUploadResult.errors.length - 3} more`}
                  </div>
                )}
              </div>
            )}
            <div className="flex flex-wrap items-center gap-2 border-l pl-2 ml-1">
              <span className="text-xs text-muted-foreground">Multi-courier:</span>
              {couriers.map((courier) => (
                <label
                  key={courier.value}
                  className="flex items-center gap-1.5 text-sm text-muted-foreground cursor-pointer"
                >
                  <input
                    type="checkbox"
                    checked={selectedCouriers.includes(courier.value)}
                    onChange={() => toggleCourier(courier.value)}
                    className="h-4 w-4"
                  />
                  {courier.label}
                </label>
              ))}
              <Button
                variant="default"
                onClick={exportSelectedCouriers}
                disabled={selectedCouriers.length === 0}
                size="sm"
              >
                <Truck className="mr-1.5 h-4 w-4" />
                Export {selectedCouriers.length > 0 ? `(${selectedCouriers.length})` : ''}
              </Button>
            </div>
            <Button variant="outline" onClick={loadAnalytics} disabled={analyticsLoading}>
              <BarChart3 className="mr-1.5 h-4 w-4" />
              {analyticsLoading ? 'Loading...' : 'Analytics'}
            </Button>
          </div>
        </div>

        {showAnalytics && analytics && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Export Batch Analytics</CardTitle>
                  <CardDescription>Success rates across recent batches</CardDescription>
                </div>
                <button
                  type="button"
                  onClick={() => setShowAnalytics(false)}
                  className="text-muted-foreground hover:text-foreground"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Total Batches</p>
                  <p className="text-lg font-semibold">{analytics.summary.total_batches}</p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Total Rows</p>
                  <p className="text-lg font-semibold">{analytics.summary.total_rows}</p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Exported</p>
                  <p className="text-lg font-semibold text-green-600">
                    {analytics.summary.total_exported}
                  </p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Failed</p>
                  <p className="text-lg font-semibold text-destructive">
                    {analytics.summary.total_failed}
                  </p>
                </div>
                <div className="rounded-lg border p-3">
                  <p className="text-xs text-muted-foreground">Success Rate</p>
                  <p className="text-lg font-semibold">{analytics.summary.overall_success_rate}%</p>
                </div>
              </div>

              {analytics.by_courier.length > 0 && (
                <div>
                  <p className="mb-2 text-sm font-medium">By Courier</p>
                  <div className="space-y-2">
                    {analytics.by_courier.map((c) => (
                      <div
                        key={c.courier}
                        className="flex items-center gap-3 rounded-md border px-3 py-2 text-xs"
                      >
                        <span className="font-medium">{c.courier}</span>
                        <span className="text-muted-foreground">{c.batch_count} batches</span>
                        <span className="text-muted-foreground">{c.total_rows} rows</span>
                        <span className="text-green-600">{c.exported_rows} exported</span>
                        {c.failed_rows > 0 && (
                          <span className="text-destructive">{c.failed_rows} failed</span>
                        )}
                        <span className="ml-auto font-medium">{c.success_rate}%</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              <div>
                <p className="mb-2 text-sm font-medium">Per Batch</p>
                <div className="max-h-60 overflow-auto rounded-md border">
                  <table className="w-full text-xs">
                    <thead className="sticky top-0 bg-muted/50">
                      <tr>
                        <th className="px-2 py-1.5 text-left font-medium">Batch</th>
                        <th className="px-2 py-1.5 text-left font-medium">Courier</th>
                        <th className="px-2 py-1.5 text-left font-medium">Status</th>
                        <th className="px-2 py-1.5 text-right font-medium">Rows</th>
                        <th className="px-2 py-1.5 text-right font-medium">Exported</th>
                        <th className="px-2 py-1.5 text-right font-medium">Failed</th>
                        <th className="px-2 py-1.5 text-right font-medium">Success</th>
                      </tr>
                    </thead>
                    <tbody>
                      {analytics.per_batch.map((b) => (
                        <tr key={b.id} className="border-t">
                          <td className="px-2 py-1.5 font-medium">{b.batch_number}</td>
                          <td className="px-2 py-1.5">{b.courier_code}</td>
                          <td className="px-2 py-1.5">{b.status}</td>
                          <td className="px-2 py-1.5 text-right">{b.total_rows}</td>
                          <td className="px-2 py-1.5 text-right text-green-600">
                            {b.exported_rows}
                          </td>
                          <td className="px-2 py-1.5 text-right text-destructive">
                            {b.failed_rows}
                          </td>
                          <td className="px-2 py-1.5 text-right font-medium">{b.success_rate}%</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

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
                        {order.address_flags && order.address_flags.length > 0 && (
                          <Badge variant="destructive" className="text-xs">
                            <AlertTriangle className="mr-1 h-3 w-3" />
                            {order.address_flags.length} issue
                            {order.address_flags.length > 1 ? 's' : ''}
                          </Badge>
                        )}
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-2 text-sm">
                    {order.address_flags && order.address_flags.length > 0 && (
                      <div className="flex flex-wrap gap-1">
                        {order.address_flags.map((flag) => (
                          <Badge
                            key={flag}
                            variant="outline"
                            className="border-destructive/40 text-destructive text-[10px]"
                          >
                            {flag.replace(/_/g, ' ')}
                          </Badge>
                        ))}
                      </div>
                    )}
                    <p>{order.receiver_address}</p>
                    <p className="text-muted-foreground">
                      {[order.barangay, order.city, order.state].filter(Boolean).join(', ') ||
                        'No structured location'}
                    </p>
                    <p className="text-muted-foreground">{orderSummary(order)}</p>
                    <AddressEditor
                      order={order}
                      hasFlags={Boolean(order.address_flags && order.address_flags.length > 0)}
                    />
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

      {showValidationReport && validationReport && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[80vh] w-full max-w-2xl overflow-auto rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Address Validation Report</h2>
              <button onClick={() => setShowValidationReport(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="mb-4 grid grid-cols-3 gap-3 text-center">
              <div className="rounded-md border p-3">
                <div className="text-2xl font-bold">{validationReport.total_orders}</div>
                <div className="text-xs text-muted-foreground">Total Orders</div>
              </div>
              <div className="rounded-md border border-green-500/40 p-3">
                <div className="text-2xl font-bold text-green-600">
                  {validationReport.valid_orders}
                </div>
                <div className="text-xs text-muted-foreground">Valid</div>
              </div>
              <div className="rounded-md border border-destructive/40 p-3">
                <div className="text-2xl font-bold text-destructive">
                  {validationReport.orders_with_issues}
                </div>
                <div className="text-xs text-muted-foreground">With Issues</div>
              </div>
            </div>
            {validationReport.orders_with_issues > 0 && (
              <>
                <div className="mb-3 flex flex-wrap gap-2">
                  {Object.entries(validationReport.issue_summary).map(([key, count]) =>
                    count > 0 ? (
                      <Badge
                        key={key}
                        variant="outline"
                        className="border-destructive/40 text-destructive text-xs"
                      >
                        {key.replace(/_/g, ' ')}: {count}
                      </Badge>
                    ) : null
                  )}
                </div>
                <div className="mb-4 max-h-48 overflow-auto rounded-md border">
                  <table className="w-full text-xs">
                    <thead className="sticky top-0 bg-muted">
                      <tr>
                        <th className="px-2 py-1.5 text-left">Order</th>
                        <th className="px-2 py-1.5 text-left">Customer</th>
                        <th className="px-2 py-1.5 text-left">Issues</th>
                        <th className="px-2 py-1.5 text-right">Confidence</th>
                      </tr>
                    </thead>
                    <tbody>
                      {validationReport.orders.map((o) => (
                        <tr key={o.id} className="border-t">
                          <td className="px-2 py-1.5 font-mono">{o.order_number}</td>
                          <td className="px-2 py-1.5">{o.receiver_name}</td>
                          <td className="px-2 py-1.5 text-destructive">{o.issues.join(', ')}</td>
                          <td className="px-2 py-1.5 text-right">{o.address_confidence}%</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {pendingExport ? (
                  <div className="flex items-center justify-end gap-2 border-t pt-4">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setPendingExport(null);
                        setShowValidationReport(false);
                      }}
                    >
                      Cancel Export
                    </Button>
                    <Button size="sm" onClick={proceedExport}>
                      Export Anyway (Skip {validationReport.orders_with_issues} Order
                      {validationReport.orders_with_issues > 1 ? 's' : ''})
                    </Button>
                  </div>
                ) : (
                  <div className="flex items-center justify-end gap-2 border-t pt-4">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setShowValidationReport(false)}
                    >
                      Close
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => {
                        setShowValidationReport(false);
                        router.get('/shop/encoder', { needs_review: 1 }, { preserveScroll: true });
                      }}
                    >
                      Go to Needs Review
                    </Button>
                  </div>
                )}
              </>
            )}
            {validationReport.orders_with_issues === 0 && (
              <div className="flex items-center justify-end gap-2 border-t pt-4">
                <Button
                  size="sm"
                  onClick={() => {
                    setShowValidationReport(false);
                    if (pendingExport) proceedExport();
                  }}
                >
                  All Clear — Continue
                </Button>
              </div>
            )}
          </div>
        </div>
      )}

      {validationReportLoading && !showValidationReport && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <p className="text-sm text-background">Checking addresses...</p>
        </div>
      )}
    </AppLayout>
  );
}
