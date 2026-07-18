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
  Clock,
  FileText,
  UserCog,
  Printer,
  DollarSign,
  Copy,
  PauseCircle,
  PlayCircle,
  Tag as TagIcon,
  SplitSquareHorizontal,
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
  postal_code?: string | null;
  courier_code?: string | null;
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
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
  orders: Paginated<Order>;
  recent_batches: Batch[];
  couriers: { value: string; label: string }[];
  filters?: { needs_review?: boolean };
  encoders?: { id: number; name: string }[];
  tags?: { id: number; name: string; slug: string; color: string }[];
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
  const [prevAddressLoading, setPrevAddressLoading] = useState(false);
  const [prevAddressSuggestions, setPrevAddressSuggestions] = useState<
    {
      order_number: string;
      receiver_address: string;
      barangay: string | null;
      city: string | null;
      state: string | null;
      postal_code: string | null;
      landmark: string | null;
      nearest_landmark: string | null;
      created_at: string;
    }[]
  >([]);
  const [showPrevAddresses, setShowPrevAddresses] = useState(false);
  const [formatLoading, setFormatLoading] = useState(false);
  const [formatResult, setFormatResult] = useState<{
    formatted: string;
    fields: Record<string, string>;
    courier: string;
    notes: string[];
  } | null>(null);
  const [selectedCourier, setSelectedCourier] = useState<string>('');

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

  const loadPrevAddresses = () => {
    if (showPrevAddresses) {
      setShowPrevAddresses(false);
      return;
    }
    setPrevAddressLoading(true);
    fetch(`/shop/encoder/orders/${order.id}/suggest-address`, {
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => {
        setPrevAddressSuggestions(data.suggestions ?? []);
        setShowPrevAddresses(true);
      })
      .finally(() => setPrevAddressLoading(false));
  };

  const applyPrevAddress = (s: (typeof prevAddressSuggestions)[0]) => {
    setForm({
      receiver_address: s.receiver_address ?? '',
      barangay: s.barangay ?? '',
      city: s.city ?? '',
      state: s.state ?? '',
      landmark: s.landmark ?? '',
      nearest_landmark: s.nearest_landmark ?? '',
      notes: '',
    });
    setShowPrevAddresses(false);
  };

  const loadCourierFormat = (courier?: string) => {
    const code = courier ?? selectedCourier ?? order.courier_code ?? 'GENERIC';
    setSelectedCourier(code);
    setFormatLoading(true);
    fetch(`/shop/encoder/orders/${order.id}/format-address?courier=${encodeURIComponent(code)}`, {
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => setFormatResult(data))
      .finally(() => setFormatLoading(false));
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
        <Button size="sm" variant="ghost" onClick={loadPrevAddresses} disabled={prevAddressLoading}>
          <Clock className="mr-1.5 h-3.5 w-3.5" />
          {prevAddressLoading
            ? 'Loading...'
            : showPrevAddresses
              ? 'Hide Previous'
              : 'Previous Orders'}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => loadCourierFormat()}
          disabled={formatLoading}
        >
          <FileText className="mr-1.5 h-3.5 w-3.5" />
          {formatLoading ? 'Formatting...' : 'Courier Format'}
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
      {showPrevAddresses && (
        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">
              Previous Addresses ({prevAddressSuggestions.length})
            </span>
            <button onClick={() => setShowPrevAddresses(false)}>
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
          {prevAddressSuggestions.length === 0 ? (
            <p className="text-xs text-muted-foreground">
              No previous addresses found for this customer.
            </p>
          ) : (
            <div className="space-y-2">
              {prevAddressSuggestions.map((s) => (
                <div key={s.order_number} className="rounded border p-2 text-xs">
                  <div className="mb-1 flex items-center justify-between">
                    <span className="font-medium">{s.order_number}</span>
                    <span className="text-muted-foreground">
                      {new Date(s.created_at).toLocaleDateString()}
                    </span>
                  </div>
                  <p className="text-muted-foreground">
                    {s.receiver_address}
                    {s.barangay && `, ${s.barangay}`}
                    {s.city && `, ${s.city}`}
                    {s.state && `, ${s.state}`}
                  </p>
                  <button
                    className="mt-1 text-blue-600 hover:underline"
                    onClick={() => applyPrevAddress(s)}
                  >
                    Use this address
                  </button>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
      {formatResult && (
        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-muted-foreground">
              {formatResult.courier} Address Format
            </span>
            <div className="flex items-center gap-2">
              <select
                className="rounded border px-1.5 py-0.5 text-xs"
                value={selectedCourier}
                onChange={(e) => loadCourierFormat(e.target.value)}
              >
                <option value="">Auto ({order.courier_code ?? 'GENERIC'})</option>
                <option value="FLASH">FLASH</option>
                <option value="JNT">JNT</option>
                <option value="GENERIC">Generic</option>
              </select>
              <button onClick={() => setFormatResult(null)}>
                <X className="h-3.5 w-3.5" />
              </button>
            </div>
          </div>
          <div className="rounded bg-muted p-2">
            <p className="font-mono text-xs">{formatResult.formatted}</p>
          </div>
          <div className="space-y-1">
            <span className="text-xs text-muted-foreground">Field mapping:</span>
            {Object.entries(formatResult.fields).map(([key, value]) => (
              <div key={key} className="flex items-center gap-2 text-xs">
                <span className="font-mono text-muted-foreground">{key}:</span>
                <span>{value || <em className="text-muted-foreground">empty</em>}</span>
              </div>
            ))}
          </div>
          {formatResult.notes.length > 0 && (
            <div className="space-y-1">
              {formatResult.notes.map((note, i) => (
                <p key={i} className="text-xs text-blue-600">
                  &bull; {note}
                </p>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

export default function ShopEncoder({
  orders,
  recent_batches,
  couriers,
  filters,
  encoders,
  tags,
}: Props) {
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
  const [addrAnalytics, setAddrAnalytics] = useState<{
    total_orders: number;
    avg_confidence: number;
    confidence_distribution: Record<string, number>;
    issue_summary: Record<string, number>;
    orders_with_issues: number;
    orders_valid: number;
    geocoding: { geocoded: number; not_geocoded: number; coverage_pct: number };
    encoding: { encoded: number; not_encoded: number };
    corrections: {
      total: number;
      by_action: Record<string, number>;
      avg_confidence_before: number;
      avg_confidence_after: number;
      avg_improvement: number;
    };
    top_provinces_with_issues: Record<string, number>;
  } | null>(null);
  const [addrAnalyticsLoading, setAddrAnalyticsLoading] = useState(false);
  const [showAddrAnalytics, setShowAddrAnalytics] = useState(false);
  const [showBulkStatus, setShowBulkStatus] = useState(false);
  const [bulkStatusLoading, setBulkStatusLoading] = useState(false);
  const [bulkStatusValue, setBulkStatusValue] = useState('');
  const [bulkStatusReason, setBulkStatusReason] = useState('');
  const [bulkStatusResult, setBulkStatusResult] = useState<{
    updated: number;
    skipped: number;
    errors: string[];
  } | null>(null);
  const [showBulkAssign, setShowBulkAssign] = useState(false);
  const [bulkAssignEncoderId, setBulkAssignEncoderId] = useState('');
  const [bulkAssignLoading, setBulkAssignLoading] = useState(false);
  const [bulkAssignResult, setBulkAssignResult] = useState<{
    updated: number;
    skipped: number;
    errors: string[];
  } | null>(null);
  const [showPrintLabels, setShowPrintLabels] = useState(false);
  const [printLabelsLoading, setPrintLabelsLoading] = useState(false);
  const [printLabelsData, setPrintLabelsData] = useState<{
    labels: Array<{
      order_number: string;
      receiver_name: string;
      receiver_phone: string;
      address_line: string;
      courier_code: string;
      cod_amount: number;
      quantity: number;
      product_name: string;
    }>;
    count: number;
  } | null>(null);
  const [showCodVerify, setShowCodVerify] = useState(false);
  const [codVerifyLoading, setCodVerifyLoading] = useState(false);
  const [codVerifyData, setCodVerifyData] = useState<{
    items: Array<{
      id: number;
      order_number: string;
      receiver_name: string;
      quantity: number;
      unit_price: number;
      subtotal: number;
      shipping_cost: number;
      discount_amount: number;
      tax_amount: number;
      expected_cod: number;
      actual_cod: number;
      discrepancy: number;
      is_correct: boolean;
    }>;
    total: number;
    correct: number;
    discrepant: number;
    total_discrepancy: number;
  } | null>(null);
  const [codEdits, setCodEdits] = useState<Record<number, string>>({});
  const [codUpdateLoading, setCodUpdateLoading] = useState(false);
  const [codUpdateResult, setCodUpdateResult] = useState<{
    updated: number;
    errors: string[];
  } | null>(null);
  const [showDupDetect, setShowDupDetect] = useState(false);
  const [dupDetectLoading, setDupDetectLoading] = useState(false);
  const [dupDetectData, setDupDetectData] = useState<{
    groups: Array<{
      match_type: string;
      phone: string;
      product?: { id: number; name: string; sku: string } | null;
      address?: string;
      order_count: number;
      orders: Array<{
        id: number;
        order_number: string;
        receiver_name: string;
        receiver_phone: string;
        quantity: number;
        total_amount: number;
        cod_amount: number;
        status: string;
        created_at: string;
      }>;
    }>;
    group_count: number;
    orders_in_groups: number;
    total_checked: number;
    unique_orders: number;
  } | null>(null);
  const [showHoldRelease, setShowHoldRelease] = useState(false);
  const [holdReleaseAction, setHoldReleaseAction] = useState<'hold' | 'release'>('hold');
  const [holdReleaseReason, setHoldReleaseReason] = useState('');
  const [holdReleaseLoading, setHoldReleaseLoading] = useState(false);
  const [holdReleaseResult, setHoldReleaseResult] = useState<{
    held: number;
    released: number;
    skipped: number;
    errors: string[];
  } | null>(null);
  const [showTagUpdate, setShowTagUpdate] = useState(false);
  const [tagMode, setTagMode] = useState<'add' | 'replace' | 'remove'>('add');
  const [selectedTagIds, setSelectedTagIds] = useState<number[]>([]);
  const [tagUpdateLoading, setTagUpdateLoading] = useState(false);
  const [tagUpdateResult, setTagUpdateResult] = useState<{
    updated: number;
    mode: string;
    tag_count: number;
    errors: string[];
  } | null>(null);
  const [showSplitRegion, setShowSplitRegion] = useState(false);
  const [splitRegionLoading, setSplitRegionLoading] = useState(false);
  const [splitRegionData, setSplitRegionData] = useState<{
    groups: {
      region: string;
      order_count: number;
      total_amount: number;
      cod_amount: number;
      couriers: string[];
      orders: {
        id: number;
        order_number: string;
        receiver_name: string;
        receiver_phone: string;
        city: string;
        barangay: string;
        courier_code: string;
        status: string;
        quantity: number;
        total_amount: number;
        cod_amount: number;
        created_at: string;
      }[];
    }[];
    region_count: number;
    total_orders: number;
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

  const loadAddrAnalytics = () => {
    setAddrAnalyticsLoading(true);
    fetch('/shop/encoder/address-analytics', { headers: { Accept: 'application/json' } })
      .then((res) => res.json())
      .then((data) => {
        setAddrAnalytics(data);
        setShowAddrAnalytics(true);
      })
      .finally(() => setAddrAnalyticsLoading(false));
  };

  const handleBulkStatusUpdate = () => {
    if (!bulkStatusValue || selectedOrderIds.length === 0) return;
    setBulkStatusLoading(true);
    setBulkStatusResult(null);
    fetch('/shop/encoder/bulk-status-update', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({
        order_ids: selectedOrderIds,
        status: bulkStatusValue,
        reason: bulkStatusReason || undefined,
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        setBulkStatusResult(data);
        if (data.updated > 0) {
          setShowBulkStatus(false);
          setBulkStatusValue('');
          setBulkStatusReason('');
          router.reload();
        }
      })
      .catch(() => setBulkStatusResult({ updated: 0, skipped: 0, errors: ['Request failed.'] }))
      .finally(() => setBulkStatusLoading(false));
  };

  const handleBulkAssignEncoder = () => {
    if (!bulkAssignEncoderId || selectedOrderIds.length === 0) return;
    setBulkAssignLoading(true);
    setBulkAssignResult(null);
    fetch('/shop/encoder/bulk-assign-encoder', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({
        order_ids: selectedOrderIds,
        encoder_id: parseInt(bulkAssignEncoderId, 10),
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        setBulkAssignResult(data);
        if (data.updated > 0) {
          setShowBulkAssign(false);
          setBulkAssignEncoderId('');
          router.reload();
        }
      })
      .catch(() => setBulkAssignResult({ updated: 0, skipped: 0, errors: ['Request failed.'] }))
      .finally(() => setBulkAssignLoading(false));
  };

  const handlePrintLabels = () => {
    if (selectedOrderIds.length === 0) return;
    setPrintLabelsLoading(true);
    fetch('/shop/encoder/bulk-print-labels', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({ order_ids: selectedOrderIds }),
    })
      .then((res) => res.json())
      .then((data) => {
        setPrintLabelsData(data);
        setShowPrintLabels(true);
      })
      .finally(() => setPrintLabelsLoading(false));
  };

  const handleCodVerify = () => {
    if (selectedOrderIds.length === 0) return;
    setCodVerifyLoading(true);
    setCodEdits({});
    setCodUpdateResult(null);
    fetch('/shop/encoder/bulk-cod-verify', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({ order_ids: selectedOrderIds }),
    })
      .then((res) => res.json())
      .then((data) => {
        setCodVerifyData(data);
        setShowCodVerify(true);
      })
      .finally(() => setCodVerifyLoading(false));
  };

  const handleCodUpdate = () => {
    const updates = Object.entries(codEdits)
      .filter(([, value]) => value !== '')
      .map(([orderId, amount]) => ({
        order_id: parseInt(orderId, 10),
        cod_amount: parseFloat(amount),
      }));
    if (updates.length === 0) return;
    setCodUpdateLoading(true);
    setCodUpdateResult(null);
    fetch('/shop/encoder/bulk-cod-update', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({ updates }),
    })
      .then((res) => res.json())
      .then((data) => {
        setCodUpdateResult(data);
        if (data.updated > 0) {
          setCodEdits({});
          router.reload();
        }
      })
      .finally(() => setCodUpdateLoading(false));
  };

  const handleDupDetect = () => {
    if (selectedOrderIds.length === 0) return;
    setDupDetectLoading(true);
    fetch('/shop/encoder/bulk-duplicate-detect', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({ order_ids: selectedOrderIds }),
    })
      .then((res) => res.json())
      .then((data) => {
        setDupDetectData(data);
        setShowDupDetect(true);
      })
      .finally(() => setDupDetectLoading(false));
  };

  const handleHoldRelease = () => {
    if (selectedOrderIds.length === 0) return;
    setHoldReleaseLoading(true);
    setHoldReleaseResult(null);
    fetch('/shop/encoder/bulk-hold-release', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({
        order_ids: selectedOrderIds,
        action: holdReleaseAction,
        reason: holdReleaseReason || undefined,
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        setHoldReleaseResult(data);
        if (data.held > 0 || data.released > 0) {
          setShowHoldRelease(false);
          setHoldReleaseReason('');
          router.reload();
        }
      })
      .finally(() => setHoldReleaseLoading(false));
  };

  const handleTagUpdate = () => {
    if (selectedOrderIds.length === 0) return;
    setTagUpdateLoading(true);
    setTagUpdateResult(null);
    fetch('/shop/encoder/bulk-tag-update', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({
        order_ids: selectedOrderIds,
        tag_ids: selectedTagIds,
        mode: tagMode,
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        setTagUpdateResult(data);
        if (data.updated > 0) {
          setShowTagUpdate(false);
          setSelectedTagIds([]);
          router.reload();
        }
      })
      .finally(() => setTagUpdateLoading(false));
  };

  const toggleTag = (tagId: number) => {
    setSelectedTagIds((current) =>
      current.includes(tagId) ? current.filter((id) => id !== tagId) : [...current, tagId]
    );
  };

  const handleSplitByRegion = () => {
    if (selectedOrderIds.length === 0) return;
    setSplitRegionLoading(true);
    fetch('/shop/encoder/bulk-split-by-region', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({ order_ids: selectedOrderIds }),
    })
      .then((res) => res.json())
      .then((data) => {
        setSplitRegionData(data);
        setShowSplitRegion(true);
      })
      .finally(() => setSplitRegionLoading(false));
  };

  const toggleOrder = (orderId: number) => {
    setSelectedOrderIds((current) =>
      current.includes(orderId) ? current.filter((id) => id !== orderId) : [...current, orderId]
    );
  };

  const toggleAll = () => {
    const currentPageIds = orders.data.map((order) => order.id);
    const allCurrentPageSelected = currentPageIds.every((id) => selectedOrderIds.includes(id));
    if (allCurrentPageSelected) {
      setSelectedOrderIds((current) => current.filter((id) => !currentPageIds.includes(id)));
    } else {
      setSelectedOrderIds((current) => [...new Set([...current, ...currentPageIds])]);
    }
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
                {orders.data.every((o) => selectedOrderIds.includes(o.id))
                  ? 'Clear Page Selection'
                  : 'Select Page'}
              </Button>
            )}
            {selectedOrderIds.length > 0 && (
              <Button variant="ghost" size="sm" onClick={() => setSelectedOrderIds([])}>
                Clear All ({selectedOrderIds.length})
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
                  { preserveState: true, preserveScroll: true }
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
            <Button
              variant="outline"
              size="sm"
              onClick={loadAddrAnalytics}
              disabled={addrAnalyticsLoading}
            >
              <BarChart3 className="mr-1.5 h-3.5 w-3.5" />
              {addrAnalyticsLoading ? 'Loading...' : 'Address Analytics'}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setShowBulkStatus(true)}
              disabled={selectedOrderIds.length === 0}
            >
              <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
              Bulk Status
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setShowBulkAssign(true)}
              disabled={selectedOrderIds.length === 0 || !encoders?.length}
            >
              <UserCog className="mr-1.5 h-3.5 w-3.5" />
              Assign Encoder
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handlePrintLabels}
              disabled={selectedOrderIds.length === 0 || printLabelsLoading}
            >
              <Printer className="mr-1.5 h-3.5 w-3.5" />
              {printLabelsLoading ? 'Loading...' : 'Print Labels'}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleCodVerify}
              disabled={selectedOrderIds.length === 0 || codVerifyLoading}
            >
              <DollarSign className="mr-1.5 h-3.5 w-3.5" />
              {codVerifyLoading ? 'Loading...' : 'Verify COD'}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleDupDetect}
              disabled={selectedOrderIds.length === 0 || dupDetectLoading}
            >
              <Copy className="mr-1.5 h-3.5 w-3.5" />
              {dupDetectLoading ? 'Scanning...' : 'Detect Duplicates'}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setHoldReleaseAction('hold');
                setHoldReleaseResult(null);
                setShowHoldRelease(true);
              }}
              disabled={selectedOrderIds.length === 0}
            >
              <PauseCircle className="mr-1.5 h-3.5 w-3.5" />
              Hold
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setHoldReleaseAction('release');
                setHoldReleaseResult(null);
                setShowHoldRelease(true);
              }}
              disabled={selectedOrderIds.length === 0}
            >
              <PlayCircle className="mr-1.5 h-3.5 w-3.5" />
              Release
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setTagMode('add');
                setSelectedTagIds([]);
                setTagUpdateResult(null);
                setShowTagUpdate(true);
              }}
              disabled={selectedOrderIds.length === 0 || !tags?.length}
            >
              <TagIcon className="mr-1.5 h-3.5 w-3.5" />
              Tag Orders
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={handleSplitByRegion}
              disabled={selectedOrderIds.length === 0 || splitRegionLoading}
            >
              <SplitSquareHorizontal className="mr-1.5 h-3.5 w-3.5" />
              {splitRegionLoading ? 'Splitting...' : 'Split by Region'}
            </Button>
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
              ? `${selectedOrderIds.length} of ${orders.total} orders selected across pages. Bulk actions will apply to all selected.`
              : `Showing ${orders.from ?? 0}-${orders.to ?? 0} of ${orders.total} orders. No orders selected — export will include all encoder-ready orders.`}
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

          {orders.last_page > 1 && (
            <div className="flex items-center justify-center gap-1">
              {orders.links.map((link, i) => (
                <button
                  key={i}
                  disabled={!link.url}
                  onClick={() => {
                    if (link.url) {
                      router.get(link.url!, {}, { preserveState: true, preserveScroll: true });
                    }
                  }}
                  className={`min-w-[2rem] rounded-md border px-2 py-1 text-xs transition-colors ${
                    link.active
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'hover:bg-muted'
                  } ${!link.url ? 'cursor-not-allowed opacity-50' : ''}`}
                  dangerouslySetInnerHTML={{ __html: link.label }}
                />
              ))}
            </div>
          )}

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
                        router.get(
                          '/shop/encoder',
                          { needs_review: 1 },
                          { preserveState: true, preserveScroll: true }
                        );
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
      {showAddrAnalytics && addrAnalytics && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Address Validation Analytics</h2>
              <button onClick={() => setShowAddrAnalytics(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>

            {/* Summary stats */}
            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold">{addrAnalytics.total_orders}</p>
                <p className="text-xs text-muted-foreground">Total Orders</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-green-600">{addrAnalytics.avg_confidence}</p>
                <p className="text-xs text-muted-foreground">Avg Confidence</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-green-600">{addrAnalytics.orders_valid}</p>
                <p className="text-xs text-muted-foreground">Valid Addresses</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-red-600">
                  {addrAnalytics.orders_with_issues}
                </p>
                <p className="text-xs text-muted-foreground">With Issues</p>
              </div>
            </div>

            {/* Confidence distribution */}
            <div className="mb-6">
              <h3 className="mb-2 text-sm font-semibold">Confidence Distribution</h3>
              <div className="space-y-1.5">
                {Object.entries(addrAnalytics.confidence_distribution).map(([bucket, count]) => {
                  const pct =
                    addrAnalytics.total_orders > 0 ? (count / addrAnalytics.total_orders) * 100 : 0;
                  const color =
                    bucket === '91-100'
                      ? 'bg-green-500'
                      : bucket === '76-90'
                        ? 'bg-green-400'
                        : bucket === '51-75'
                          ? 'bg-yellow-400'
                          : bucket === '26-50'
                            ? 'bg-orange-400'
                            : 'bg-red-500';
                  return (
                    <div key={bucket} className="flex items-center gap-2 text-xs">
                      <span className="w-16 text-muted-foreground">{bucket}</span>
                      <div className="relative h-5 flex-1 rounded bg-muted">
                        <div
                          className={`absolute left-0 top-0 h-5 rounded ${color}`}
                          style={{ width: `${Math.max(pct, 2)}%` }}
                        />
                        <span className="absolute left-2 top-0 flex h-5 items-center text-xs font-medium">
                          {count} ({pct.toFixed(1)}%)
                        </span>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Issue breakdown */}
            <div className="mb-6">
              <h3 className="mb-2 text-sm font-semibold">Issue Breakdown</h3>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {Object.entries(addrAnalytics.issue_summary).map(([key, count]) => (
                  <div
                    key={key}
                    className="flex items-center justify-between rounded border px-2 py-1.5 text-xs"
                  >
                    <span className="text-muted-foreground">{key.replace(/_/g, ' ')}</span>
                    <span className={`font-bold ${count > 0 ? 'text-red-600' : 'text-green-600'}`}>
                      {count}
                    </span>
                  </div>
                ))}
              </div>
            </div>

            {/* Geocoding & Encoding */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <h3 className="mb-2 text-sm font-semibold">Geocoding Coverage</h3>
                <div className="flex items-center gap-3 text-xs">
                  <span className="text-green-600">
                    {addrAnalytics.geocoding.geocoded} geocoded
                  </span>
                  <span className="text-muted-foreground">
                    {addrAnalytics.geocoding.not_geocoded} not geocoded
                  </span>
                  <span className="font-bold">{addrAnalytics.geocoding.coverage_pct}%</span>
                </div>
                <div className="mt-1 h-3 rounded bg-muted">
                  <div
                    className="h-3 rounded bg-blue-500"
                    style={{ width: `${addrAnalytics.geocoding.coverage_pct}%` }}
                  />
                </div>
              </div>
              <div>
                <h3 className="mb-2 text-sm font-semibold">Encoding Status</h3>
                <div className="flex items-center gap-3 text-xs">
                  <span className="text-green-600">{addrAnalytics.encoding.encoded} encoded</span>
                  <span className="text-muted-foreground">
                    {addrAnalytics.encoding.not_encoded} pending
                  </span>
                </div>
              </div>
            </div>

            {/* Correction history stats */}
            <div className="mb-6">
              <h3 className="mb-2 text-sm font-semibold">Correction History</h3>
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div className="rounded border p-2 text-center text-xs">
                  <p className="text-lg font-bold">{addrAnalytics.corrections.total}</p>
                  <p className="text-muted-foreground">Total Corrections</p>
                </div>
                <div className="rounded border p-2 text-center text-xs">
                  <p className="text-lg font-bold text-orange-600">
                    {addrAnalytics.corrections.avg_confidence_before}
                  </p>
                  <p className="text-muted-foreground">Avg Before</p>
                </div>
                <div className="rounded border p-2 text-center text-xs">
                  <p className="text-lg font-bold text-green-600">
                    {addrAnalytics.corrections.avg_confidence_after}
                  </p>
                  <p className="text-muted-foreground">Avg After</p>
                </div>
                <div className="rounded border p-2 text-center text-xs">
                  <p className="text-lg font-bold text-blue-600">
                    +{addrAnalytics.corrections.avg_improvement}
                  </p>
                  <p className="text-muted-foreground">Avg Improvement</p>
                </div>
              </div>
              {Object.keys(addrAnalytics.corrections.by_action).length > 0 && (
                <div className="mt-2 flex flex-wrap gap-2">
                  {Object.entries(addrAnalytics.corrections.by_action).map(([action, count]) => (
                    <Badge key={action} variant="secondary" className="text-xs">
                      {action}: {count}
                    </Badge>
                  ))}
                </div>
              )}
            </div>

            {/* Top provinces with issues */}
            {Object.keys(addrAnalytics.top_provinces_with_issues).length > 0 && (
              <div>
                <h3 className="mb-2 text-sm font-semibold">Top Provinces with Issues</h3>
                <div className="space-y-1">
                  {Object.entries(addrAnalytics.top_provinces_with_issues).map(
                    ([province, count]) => (
                      <div key={province} className="flex items-center justify-between text-xs">
                        <span className="text-muted-foreground">{province}</span>
                        <span className="font-bold text-red-600">{count}</span>
                      </div>
                    )
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
      {showBulkStatus && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Bulk Status Update</h2>
              <button onClick={() => setShowBulkStatus(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              Update status for <strong>{selectedOrderIds.length}</strong> selected order
              {selectedOrderIds.length !== 1 ? 's' : ''}.
            </p>
            <div className="space-y-3">
              <div>
                <label className="mb-1 block text-sm font-medium">New Status</label>
                <select
                  className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                  value={bulkStatusValue}
                  onChange={(e) => setBulkStatusValue(e.target.value)}
                >
                  <option value="">Select status...</option>
                  <option value="CONFIRMED">Confirmed</option>
                  <option value="QA_PENDING">QA Pending</option>
                  <option value="QA_APPROVED">QA Approved</option>
                  <option value="QA_REJECTED">QA Rejected</option>
                  <option value="DISPATCHED">Dispatched</option>
                  <option value="DELIVERED">Delivered</option>
                  <option value="RETURNED">Returned</option>
                  <option value="CANCELLED">Cancelled</option>
                </select>
              </div>
              {bulkStatusValue === 'QA_REJECTED' && (
                <div>
                  <label className="mb-1 block text-sm font-medium">Rejection Reason</label>
                  <Textarea
                    className="w-full"
                    rows={2}
                    value={bulkStatusReason}
                    onChange={(e) => setBulkStatusReason(e.target.value)}
                    placeholder="Reason for rejection..."
                  />
                </div>
              )}
              {bulkStatusResult && (
                <div className="rounded-md border p-2 text-xs">
                  <span className="text-green-600">{bulkStatusResult.updated} updated</span>
                  {bulkStatusResult.skipped > 0 && (
                    <>
                      {' / '}
                      <span className="text-orange-600">{bulkStatusResult.skipped} skipped</span>
                    </>
                  )}
                  {bulkStatusResult.errors.length > 0 && (
                    <div className="mt-1 text-destructive">
                      {bulkStatusResult.errors.slice(0, 3).join(', ')}
                      {bulkStatusResult.errors.length > 3 &&
                        ` +${bulkStatusResult.errors.length - 3} more`}
                    </div>
                  )}
                </div>
              )}
              <div className="flex justify-end gap-2">
                <Button variant="outline" size="sm" onClick={() => setShowBulkStatus(false)}>
                  Cancel
                </Button>
                <Button
                  size="sm"
                  onClick={handleBulkStatusUpdate}
                  disabled={!bulkStatusValue || bulkStatusLoading}
                >
                  {bulkStatusLoading ? 'Updating...' : 'Update Status'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
      {showBulkAssign && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Assign Encoder</h2>
              <button onClick={() => setShowBulkAssign(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              Assign <strong>{selectedOrderIds.length}</strong> selected order
              {selectedOrderIds.length !== 1 ? 's' : ''} to an encoder.
            </p>
            <div className="space-y-3">
              <div>
                <label className="mb-1 block text-sm font-medium">Encoder</label>
                <select
                  className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                  value={bulkAssignEncoderId}
                  onChange={(e) => setBulkAssignEncoderId(e.target.value)}
                >
                  <option value="">Select encoder...</option>
                  {encoders?.map((encoder) => (
                    <option key={encoder.id} value={encoder.id}>
                      {encoder.name}
                    </option>
                  ))}
                </select>
              </div>
              {bulkAssignResult && (
                <div className="rounded-md border p-2 text-xs">
                  <span className="text-green-600">{bulkAssignResult.updated} assigned</span>
                  {bulkAssignResult.errors.length > 0 && (
                    <div className="mt-1 text-destructive">
                      {bulkAssignResult.errors.slice(0, 3).join(', ')}
                    </div>
                  )}
                </div>
              )}
              <div className="flex justify-end gap-2">
                <Button variant="outline" size="sm" onClick={() => setShowBulkAssign(false)}>
                  Cancel
                </Button>
                <Button
                  size="sm"
                  onClick={handleBulkAssignEncoder}
                  disabled={!bulkAssignEncoderId || bulkAssignLoading}
                >
                  {bulkAssignLoading ? 'Assigning...' : 'Assign'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
      {showPrintLabels && printLabelsData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[85vh] w-full max-w-2xl overflow-auto rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Print Labels ({printLabelsData.count})</h2>
              <div className="flex items-center gap-2">
                <Button size="sm" onClick={() => window.print()}>
                  <Printer className="mr-1.5 h-3.5 w-3.5" />
                  Print
                </Button>
                <button onClick={() => setShowPrintLabels(false)}>
                  <X className="h-5 w-5" />
                </button>
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 print:grid-cols-2 sm:grid-cols-2">
              {printLabelsData.labels.map((label) => (
                <div
                  key={label.order_number}
                  className="rounded-md border-2 border-dashed border-muted-foreground/30 p-3 print:break-inside-avoid"
                >
                  <div className="mb-1 flex items-center justify-between">
                    <span className="text-xs font-bold">{label.order_number}</span>
                    <Badge variant="outline" className="text-xs">
                      {label.courier_code}
                    </Badge>
                  </div>
                  <div className="space-y-0.5 text-xs">
                    <p className="font-semibold">{label.receiver_name}</p>
                    <p className="text-muted-foreground">{label.receiver_phone}</p>
                    <p className="text-muted-foreground">{label.address_line}</p>
                    <div className="mt-1 flex items-center justify-between border-t pt-1">
                      <span className="text-muted-foreground">
                        {label.quantity}x {label.product_name}
                      </span>
                      <span className="font-bold text-green-600">
                        COD: {money(label.cod_amount)}
                      </span>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
      {showCodVerify && codVerifyData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">COD Amount Verification</h2>
              <button onClick={() => setShowCodVerify(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>

            {/* Summary stats */}
            <div className="mb-4 grid grid-cols-4 gap-3">
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold">{codVerifyData.total}</p>
                <p className="text-xs text-muted-foreground">Total</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-green-600">{codVerifyData.correct}</p>
                <p className="text-xs text-muted-foreground">Correct</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-red-600">{codVerifyData.discrepant}</p>
                <p className="text-xs text-muted-foreground">Discrepant</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p
                  className={`text-2xl font-bold ${codVerifyData.total_discrepancy >= 0 ? 'text-orange-600' : 'text-red-600'}`}
                >
                  {money(codVerifyData.total_discrepancy)}
                </p>
                <p className="text-xs text-muted-foreground">Net Discrepancy</p>
              </div>
            </div>

            {/* Items table */}
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b text-left text-muted-foreground">
                    <th className="pb-1 pr-2 font-medium">Order</th>
                    <th className="pb-1 pr-2 font-medium">Customer</th>
                    <th className="pb-1 pr-2 text-right font-medium">Subtotal</th>
                    <th className="pb-1 pr-2 text-right font-medium">Shipping</th>
                    <th className="pb-1 pr-2 text-right font-medium">Discount</th>
                    <th className="pb-1 pr-2 text-right font-medium">Tax</th>
                    <th className="pb-1 pr-2 text-right font-medium">Expected</th>
                    <th className="pb-1 pr-2 text-right font-medium">Actual</th>
                    <th className="pb-1 pr-2 text-right font-medium">Disc.</th>
                    <th className="pb-1 pr-2 font-medium">New COD</th>
                  </tr>
                </thead>
                <tbody>
                  {codVerifyData.items.map((item) => (
                    <tr
                      key={item.id}
                      className={`border-b ${item.is_correct ? '' : 'bg-red-50 dark:bg-red-950/20'}`}
                    >
                      <td className="py-1.5 pr-2 font-medium">{item.order_number}</td>
                      <td className="py-1.5 pr-2 text-muted-foreground">{item.receiver_name}</td>
                      <td className="py-1.5 pr-2 text-right">{money(item.subtotal)}</td>
                      <td className="py-1.5 pr-2 text-right">{money(item.shipping_cost)}</td>
                      <td className="py-1.5 pr-2 text-right text-red-600">
                        -{money(item.discount_amount)}
                      </td>
                      <td className="py-1.5 pr-2 text-right">{money(item.tax_amount)}</td>
                      <td className="py-1.5 pr-2 text-right font-medium">
                        {money(item.expected_cod)}
                      </td>
                      <td className="py-1.5 pr-2 text-right font-medium">
                        {money(item.actual_cod)}
                      </td>
                      <td
                        className={`py-1.5 pr-2 text-right font-bold ${item.discrepancy === 0 ? 'text-green-600' : item.discrepancy > 0 ? 'text-orange-600' : 'text-red-600'}`}
                      >
                        {item.discrepancy > 0 ? '+' : ''}
                        {money(item.discrepancy)}
                      </td>
                      <td className="py-1.5 pr-2">
                        <Input
                          type="number"
                          step="0.01"
                          className="h-7 w-24 text-xs"
                          placeholder={item.actual_cod.toFixed(2)}
                          value={codEdits[item.id] ?? ''}
                          onChange={(e) =>
                            setCodEdits((prev) => ({ ...prev, [item.id]: e.target.value }))
                          }
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Update result + action */}
            {codUpdateResult && (
              <div className="mt-3 rounded-md border p-2 text-xs">
                <span className="text-green-600">{codUpdateResult.updated} updated</span>
                {codUpdateResult.errors.length > 0 && (
                  <span className="ml-2 text-destructive">{codUpdateResult.errors.join(', ')}</span>
                )}
              </div>
            )}
            <div className="mt-4 flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setShowCodVerify(false)}>
                Close
              </Button>
              <Button
                size="sm"
                onClick={handleCodUpdate}
                disabled={Object.keys(codEdits).length === 0 || codUpdateLoading}
              >
                {codUpdateLoading
                  ? 'Updating...'
                  : `Update ${Object.keys(codEdits).length} COD Amount${Object.keys(codEdits).length !== 1 ? 's' : ''}`}
              </Button>
            </div>
          </div>
        </div>
      )}
      {showDupDetect && dupDetectData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Duplicate Detection</h2>
              <button onClick={() => setShowDupDetect(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>

            {/* Summary stats */}
            <div className="mb-4 grid grid-cols-4 gap-3">
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold">{dupDetectData.total_checked}</p>
                <p className="text-xs text-muted-foreground">Checked</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-green-600">{dupDetectData.unique_orders}</p>
                <p className="text-xs text-muted-foreground">Unique</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-orange-600">
                  {dupDetectData.orders_in_groups}
                </p>
                <p className="text-xs text-muted-foreground">In Dup Groups</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-red-600">{dupDetectData.group_count}</p>
                <p className="text-xs text-muted-foreground">Dup Groups</p>
              </div>
            </div>

            {dupDetectData.group_count === 0 ? (
              <div className="py-8 text-center text-muted-foreground">
                <Copy className="mx-auto mb-2 h-8 w-8 opacity-30" />
                <p>No duplicates found among {dupDetectData.total_checked} orders.</p>
              </div>
            ) : (
              <div className="space-y-4">
                {dupDetectData.groups.map((group, gi) => (
                  <div key={gi} className="rounded-md border border-orange-500/40 p-3">
                    <div className="mb-2 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Badge variant="outline" className="border-orange-500/50 text-orange-600">
                          {group.match_type === 'phone+product'
                            ? 'Same Phone + Product'
                            : 'Same Phone + Address'}
                        </Badge>
                        <span className="text-xs text-muted-foreground">{group.phone}</span>
                      </div>
                      <span className="text-xs font-medium text-orange-600">
                        {group.order_count} orders
                      </span>
                    </div>
                    {group.match_type === 'phone+product' && group.product && (
                      <p className="mb-2 text-xs text-muted-foreground">
                        Product: {group.product.name} ({group.product.sku})
                      </p>
                    )}
                    {group.match_type === 'phone+address' && group.address && (
                      <p className="mb-2 text-xs text-muted-foreground">Address: {group.address}</p>
                    )}
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="border-b text-left text-muted-foreground">
                          <th className="pb-1 pr-2 font-medium">Order</th>
                          <th className="pb-1 pr-2 font-medium">Customer</th>
                          <th className="pb-1 pr-2 text-right font-medium">Qty</th>
                          <th className="pb-1 pr-2 text-right font-medium">Total</th>
                          <th className="pb-1 pr-2 text-right font-medium">COD</th>
                          <th className="pb-1 pr-2 font-medium">Status</th>
                          <th className="pb-1 pr-2 font-medium">Created</th>
                        </tr>
                      </thead>
                      <tbody>
                        {group.orders.map((order) => (
                          <tr key={order.id} className="border-b last:border-0">
                            <td className="py-1.5 pr-2 font-medium">{order.order_number}</td>
                            <td className="py-1.5 pr-2 text-muted-foreground">
                              {order.receiver_name}
                            </td>
                            <td className="py-1.5 pr-2 text-right">{order.quantity}</td>
                            <td className="py-1.5 pr-2 text-right">{money(order.total_amount)}</td>
                            <td className="py-1.5 pr-2 text-right">{money(order.cod_amount)}</td>
                            <td className="py-1.5 pr-2">
                              <Badge variant="secondary" className="text-xs">
                                {order.status}
                              </Badge>
                            </td>
                            <td className="py-1.5 pr-2 text-muted-foreground">
                              {new Date(order.created_at).toLocaleDateString()}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ))}
              </div>
            )}

            <div className="mt-4 flex justify-end">
              <Button variant="outline" size="sm" onClick={() => setShowDupDetect(false)}>
                Close
              </Button>
            </div>
          </div>
        </div>
      )}
      {showHoldRelease && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">
                {holdReleaseAction === 'hold' ? 'Hold Orders' : 'Release Orders'}
              </h2>
              <button onClick={() => setShowHoldRelease(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              {holdReleaseAction === 'hold' ? (
                <>
                  Hold <strong>{selectedOrderIds.length}</strong> selected order
                  {selectedOrderIds.length !== 1 ? 's' : ''}. Held orders will be excluded from
                  exports until released.
                </>
              ) : (
                <>
                  Release <strong>{selectedOrderIds.length}</strong> selected order
                  {selectedOrderIds.length !== 1 ? 's' : ''} back to Confirmed status.
                </>
              )}
            </p>
            {holdReleaseAction === 'hold' && (
              <div className="mb-4">
                <label className="mb-1 block text-sm font-medium">Hold Reason (optional)</label>
                <Textarea
                  value={holdReleaseReason}
                  onChange={(e) => setHoldReleaseReason(e.target.value)}
                  placeholder="e.g., Waiting for customer confirmation, stock issue, etc."
                  rows={3}
                />
              </div>
            )}
            {holdReleaseResult && (
              <div className="mb-4 rounded-md border p-3 text-sm">
                {holdReleaseResult.held > 0 && (
                  <p className="text-orange-600">
                    {holdReleaseResult.held} order{holdReleaseResult.held !== 1 ? 's' : ''} held
                  </p>
                )}
                {holdReleaseResult.released > 0 && (
                  <p className="text-green-600">
                    {holdReleaseResult.released} order{holdReleaseResult.released !== 1 ? 's' : ''}{' '}
                    released
                  </p>
                )}
                {holdReleaseResult.skipped > 0 && (
                  <p className="text-muted-foreground">
                    {holdReleaseResult.skipped} skipped (invalid status)
                  </p>
                )}
              </div>
            )}
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setShowHoldRelease(false)}>
                Cancel
              </Button>
              <Button size="sm" onClick={handleHoldRelease} disabled={holdReleaseLoading}>
                {holdReleaseLoading
                  ? 'Processing...'
                  : holdReleaseAction === 'hold'
                    ? 'Hold Orders'
                    : 'Release Orders'}
              </Button>
            </div>
          </div>
        </div>
      )}
      {showTagUpdate && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-md rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Tag Orders</h2>
              <button onClick={() => setShowTagUpdate(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
              Update tags for <strong>{selectedOrderIds.length}</strong> selected order
              {selectedOrderIds.length !== 1 ? 's' : ''}.
            </p>

            {/* Mode selector */}
            <div className="mb-4">
              <label className="mb-1 block text-sm font-medium">Mode</label>
              <div className="flex gap-2">
                {(['add', 'replace', 'remove'] as const).map((m) => (
                  <button
                    key={m}
                    onClick={() => setTagMode(m)}
                    className={`rounded-md border px-3 py-1.5 text-xs font-medium transition-colors ${
                      tagMode === m
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'hover:bg-muted'
                    }`}
                  >
                    {m === 'add' ? 'Add Tags' : m === 'replace' ? 'Replace All' : 'Remove Tags'}
                  </button>
                ))}
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {tagMode === 'add' && 'Add selected tags to orders (keeps existing tags)'}
                {tagMode === 'replace' && 'Replace all tags on orders with selected tags only'}
                {tagMode === 'remove' && 'Remove selected tags from orders'}
              </p>
            </div>

            {/* Tag selection */}
            <div className="mb-4">
              <label className="mb-1 block text-sm font-medium">Tags</label>
              <div className="flex flex-wrap gap-2">
                {tags?.map((tag) => (
                  <button
                    key={tag.id}
                    onClick={() => toggleTag(tag.id)}
                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                      selectedTagIds.includes(tag.id)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'hover:bg-muted'
                    }`}
                  >
                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: tag.color }} />
                    {tag.name}
                  </button>
                ))}
                {(!tags || tags.length === 0) && (
                  <p className="text-xs text-muted-foreground">No tags available.</p>
                )}
              </div>
            </div>

            {tagUpdateResult && (
              <div className="mb-4 rounded-md border p-3 text-sm">
                <p className="text-green-600">
                  {tagUpdateResult.updated} order{tagUpdateResult.updated !== 1 ? 's' : ''} updated
                  ({tagUpdateResult.mode}, {tagUpdateResult.tag_count} tag
                  {tagUpdateResult.tag_count !== 1 ? 's' : ''})
                </p>
              </div>
            )}

            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setShowTagUpdate(false)}>
                Cancel
              </Button>
              <Button
                size="sm"
                onClick={handleTagUpdate}
                disabled={
                  tagUpdateLoading || (tagMode !== 'replace' && selectedTagIds.length === 0)
                }
              >
                {tagUpdateLoading ? 'Processing...' : 'Apply Tags'}
              </Button>
            </div>
          </div>
        </div>
      )}
      {showSplitRegion && splitRegionData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[85vh] w-full max-w-3xl overflow-auto rounded-lg border bg-background p-6 shadow-lg">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold">Split by Region</h2>
              <button onClick={() => setShowSplitRegion(false)}>
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="mb-4 grid grid-cols-3 gap-3">
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold">{splitRegionData.total_orders}</p>
                <p className="text-xs text-muted-foreground">Total Orders</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-blue-600">{splitRegionData.region_count}</p>
                <p className="text-xs text-muted-foreground">Regions</p>
              </div>
              <div className="rounded-md border p-3 text-center">
                <p className="text-2xl font-bold text-green-600">
                  {money(splitRegionData.groups.reduce((sum, g) => sum + g.total_amount, 0))}
                </p>
                <p className="text-xs text-muted-foreground">Total Value</p>
              </div>
            </div>

            {splitRegionData.groups.map((group, gi) => (
              <div key={gi} className="mb-3 rounded-md border p-3">
                <div className="mb-2 flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <Badge variant="outline" className="border-blue-500/50 text-blue-600">
                      {group.region}
                    </Badge>
                    <span className="text-xs text-muted-foreground">
                      {group.order_count} order{group.order_count !== 1 ? 's' : ''}
                    </span>
                  </div>
                  <div className="flex items-center gap-3 text-xs text-muted-foreground">
                    <span>Total: {money(group.total_amount)}</span>
                    <span>COD: {money(group.cod_amount)}</span>
                    {group.couriers.length > 0 && (
                      <span>Couriers: {group.couriers.join(', ')}</span>
                    )}
                  </div>
                </div>
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b text-left text-muted-foreground">
                      <th className="pb-1 pr-2">Order #</th>
                      <th className="pb-1 pr-2">Customer</th>
                      <th className="pb-1 pr-2">City</th>
                      <th className="pb-1 pr-2 text-right">Qty</th>
                      <th className="pb-1 pr-2 text-right">Total</th>
                      <th className="pb-1 pr-2 text-right">COD</th>
                      <th className="pb-1">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {group.orders.map((order) => (
                      <tr key={order.id} className="border-b last:border-0">
                        <td className="py-1 pr-2 font-mono">{order.order_number}</td>
                        <td className="py-1 pr-2">{order.receiver_name}</td>
                        <td className="py-1 pr-2">{order.city || '—'}</td>
                        <td className="py-1 pr-2 text-right">{order.quantity}</td>
                        <td className="py-1 pr-2 text-right">{money(order.total_amount)}</td>
                        <td className="py-1 pr-2 text-right">{money(order.cod_amount)}</td>
                        <td className="py-1">{order.status}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ))}

            <div className="mt-4 flex justify-end">
              <Button variant="outline" size="sm" onClick={() => setShowSplitRegion(false)}>
                Close
              </Button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
