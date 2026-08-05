import { useState, useEffect, useRef } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import {
  Barcode,
  Search,
  Settings as SettingsIcon,
  Printer,
  Sparkles,
  CheckCircle2,
  XCircle,
  Package,
  Boxes,
  Layers,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatCurrency, cn } from '@/lib/utils';

interface Summary {
  total_products: number;
  products_with_barcode: number;
  products_without_barcode: number;
  total_supplies: number;
  total_variants: number;
}

interface Settings {
  format: string;
  label_size: string;
  include_name: boolean;
  include_sku: boolean;
  include_price: boolean;
  include_barcode_text: boolean;
  copies: number;
  auto_generate: boolean;
}

interface LabelSize {
  width: number;
  height: number;
  cols: number;
  label: string;
}

interface Dashboard {
  summary: Summary;
  settings: Settings;
  formats: Record<string, string>;
  label_sizes: Record<string, LabelSize>;
}

interface Item {
  type: 'product' | 'variant' | 'supply';
  id: number;
  sku: string;
  name: string;
  barcode: string | null;
  qr_code: string | null;
  price: number;
  brand: string | null;
  category: string;
  label_text: string;
}

interface LabelData {
  type: string;
  id: number;
  sku: string;
  name: string | null;
  barcode: string;
  label_text: string;
  price: number | null;
  show_sku: boolean;
  show_name: boolean;
  show_price: boolean;
  show_text: boolean;
  format: string;
}

interface GeneratedLabels {
  labels: LabelData[];
  count: number;
  label_size: LabelSize;
  format: string;
}

interface Props {
  dashboard: Dashboard;
}

export default function BarcodeLabels({ dashboard }: Props) {
  const { summary, settings, formats, label_sizes } = dashboard;

  const [showSettings, setShowSettings] = useState(false);
  const [items, setItems] = useState<Item[]>([]);
  const [loadingItems, setLoadingItems] = useState(false);
  const [search, setSearch] = useState('');
  const [itemType, setItemType] = useState('all');
  const [withoutBarcode, setWithoutBarcode] = useState(false);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [generatedLabels, setGeneratedLabels] = useState<GeneratedLabels | null>(null);
  const [generating, setGenerating] = useState(false);
  const [autoGenResult, setAutoGenResult] = useState<{ generated: number } | null>(null);
  const [autoGenLoading, setAutoGenLoading] = useState(false);
  const printRef = useRef<HTMLDivElement>(null);

  const { data, setData, patch, processing } = useForm({
    format: settings.format,
    label_size: settings.label_size,
    include_name: settings.include_name,
    include_sku: settings.include_sku,
    include_price: settings.include_price,
    include_barcode_text: settings.include_barcode_text,
    copies: String(settings.copies),
    auto_generate: settings.auto_generate,
  });

  useEffect(() => {
    loadItems();
  }, []);

  function loadItems() {
    setLoadingItems(true);
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (itemType !== 'all') params.set('item_type', itemType);
    if (withoutBarcode) params.set('without_barcode', '1');
    params.set('limit', '200');

    fetch(`/inventory/barcode-labels/items?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    })
      .then((res) => res.json())
      .then((data) => setItems(data.items || []))
      .finally(() => setLoadingItems(false));
  }

  function itemKey(item: Item): string {
    return `${item.type}-${item.id}`;
  }

  function toggleSelect(item: Item) {
    const key = itemKey(item);
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  }

  function selectAll() {
    setSelectedIds(new Set(items.map(itemKey)));
  }

  function clearSelection() {
    setSelectedIds(new Set());
  }

  function handleGenerate() {
    const selectedItems = items.filter((item) => selectedIds.has(itemKey(item)));
    if (selectedItems.length === 0) return;

    setGenerating(true);
    fetch('/inventory/barcode-labels/generate', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
      body: JSON.stringify({
        items: selectedItems.map((item) => ({
          type: item.type,
          id: item.id,
          sku: item.sku,
          name: item.name,
          barcode: item.barcode,
          price: item.price,
        })),
      }),
    })
      .then((res) => res.json())
      .then((data: GeneratedLabels) => {
        setGeneratedLabels(data);
      })
      .finally(() => setGenerating(false));
  }

  function handleAutoGenerate() {
    setAutoGenLoading(true);
    setAutoGenResult(null);
    fetch('/inventory/barcode-labels/auto-generate/api', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN':
          document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
      },
    })
      .then((res) => res.json())
      .then((data) => {
        setAutoGenResult(data);
        loadItems();
      })
      .finally(() => setAutoGenLoading(false));
  }

  function handlePrint() {
    window.print();
  }

  function saveSettings() {
    patch('/inventory/barcode-labels/settings', { preserveState: true });
  }

  const selectedCount = selectedIds.size;

  return (
    <AppLayout>
      <Head title="Barcode Labels" />
      <div className="space-y-5 p-6">
        {/* Header */}
        <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
              <Link href="/inventory" className="hover:text-foreground">
                Inventory
              </Link>
              <span>/</span>
              <span>Barcode Labels</span>
            </div>
            <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
              <Barcode className="h-5 w-5 text-info" />
              Barcode Labels
            </h1>
            <p className="mt-0.5 text-sm text-muted-foreground">
              Generate and print barcode labels for products, variants, and supplies.
            </p>
          </div>
          <div className="flex gap-2">
            <Button onClick={() => setShowSettings(!showSettings)} variant="outline">
              <SettingsIcon className="mr-1.5 h-4 w-4" />
              Settings
            </Button>
            <Button onClick={handleAutoGenerate} disabled={autoGenLoading} variant="outline">
              <Sparkles className="mr-1.5 h-4 w-4" />
              {autoGenLoading ? 'Generating...' : 'Auto-Generate Missing'}
            </Button>
          </div>
        </div>

        {/* Summary stats */}
        <div className="grid grid-cols-4 gap-4">
          <StatCard
            label="Total Products"
            value={String(summary.total_products)}
            sub={`${summary.products_with_barcode} with barcode`}
            icon={<Package className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Without Barcode"
            value={String(summary.products_without_barcode)}
            sub={summary.products_without_barcode > 0 ? 'Needs generation' : 'All covered'}
            icon={<XCircle className="h-4 w-4" />}
            accent={summary.products_without_barcode > 0 ? 'warning' : 'success'}
          />
          <StatCard
            label="Variants"
            value={String(summary.total_variants)}
            sub="Product variants"
            icon={<Layers className="h-4 w-4" />}
            accent="info"
          />
          <StatCard
            label="Supplies"
            value={String(summary.total_supplies)}
            sub="Active supplies"
            icon={<Boxes className="h-4 w-4" />}
            accent="info"
          />
        </div>

        {/* Auto-gen result */}
        {autoGenResult && (
          <div className="flex items-center gap-2 rounded-lg border border-success/30 bg-success/10 p-3 text-sm">
            <CheckCircle2 className="h-4 w-4 text-success" />
            <span>
              Generated <strong>{autoGenResult.generated}</strong> barcodes for products without
              one.
            </span>
          </div>
        )}

        {/* Settings panel */}
        {showSettings && (
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Label Settings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-1">
                  <Label className="text-xs">Barcode Format</Label>
                  <Select value={data.format} onValueChange={(v) => setData('format', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(formats).map(([key, label]) => (
                        <SelectItem key={key} value={key}>
                          {label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Label Size</Label>
                  <Select value={data.label_size} onValueChange={(v) => setData('label_size', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(label_sizes).map(([key, ls]) => (
                        <SelectItem key={key} value={key}>
                          {ls.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs">Copies Per Item</Label>
                  <Select value={data.copies} onValueChange={(v) => setData('copies', v)}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {[1, 2, 3, 4, 5].map((n) => (
                        <SelectItem key={n} value={String(n)}>
                          {n} {n === 1 ? 'copy' : 'copies'}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-6">
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.include_name}
                    onCheckedChange={(v) => setData('include_name', v)}
                  />
                  <Label className="text-xs">Include Name</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.include_sku}
                    onCheckedChange={(v) => setData('include_sku', v)}
                  />
                  <Label className="text-xs">Include SKU</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.include_price}
                    onCheckedChange={(v) => setData('include_price', v)}
                  />
                  <Label className="text-xs">Include Price</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.include_barcode_text}
                    onCheckedChange={(v) => setData('include_barcode_text', v)}
                  />
                  <Label className="text-xs">Include Barcode Text</Label>
                </div>
                <div className="flex items-center gap-2">
                  <Switch
                    checked={data.auto_generate}
                    onCheckedChange={(v) => setData('auto_generate', v)}
                  />
                  <Label className="text-xs">Auto-Generate on Create</Label>
                </div>
              </div>

              <Button onClick={saveSettings} disabled={processing}>
                Save Settings
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Item picker */}
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Select Items for Label Generation</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Filters */}
            <div className="flex flex-wrap items-center gap-3">
              <div className="relative w-64">
                <Search className="absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
                <Input
                  className="pl-8"
                  placeholder="Search by SKU, name, barcode..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && loadItems()}
                />
              </div>
              <Select
                value={itemType}
                onValueChange={(v) => {
                  setItemType(v);
                }}
              >
                <SelectTrigger className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Items</SelectItem>
                  <SelectItem value="product">Products</SelectItem>
                  <SelectItem value="variant">Variants</SelectItem>
                  <SelectItem value="supply">Supplies</SelectItem>
                </SelectContent>
              </Select>
              <div className="flex items-center gap-2">
                <Checkbox
                  checked={withoutBarcode}
                  onCheckedChange={(v) => setWithoutBarcode(v === true)}
                />
                <Label className="text-xs">Without barcode only</Label>
              </div>
              <Button onClick={loadItems} variant="outline" size="sm">
                Search
              </Button>
              <div className="ml-auto flex gap-2">
                <Button onClick={selectAll} variant="ghost" size="sm">
                  Select All
                </Button>
                <Button onClick={clearSelection} variant="ghost" size="sm">
                  Clear
                </Button>
              </div>
            </div>

            {/* Items table */}
            <div className="max-h-[400px] overflow-auto rounded-md border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-10"></TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>SKU</TableHead>
                    <TableHead>Name</TableHead>
                    <TableHead>Barcode</TableHead>
                    <TableHead className="text-right">Price</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {loadingItems ? (
                    <TableRow>
                      <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                        Loading items...
                      </TableCell>
                    </TableRow>
                  ) : items.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                        No items found.
                      </TableCell>
                    </TableRow>
                  ) : (
                    items.map((item) => {
                      const key = itemKey(item);
                      const selected = selectedIds.has(key);
                      return (
                        <TableRow
                          key={key}
                          className={cn('cursor-pointer', selected && 'bg-primary/5')}
                          onClick={() => toggleSelect(item)}
                        >
                          <TableCell>
                            <Checkbox
                              checked={selected}
                              onCheckedChange={() => toggleSelect(item)}
                            />
                          </TableCell>
                          <TableCell>
                            <Badge variant="outline" className="text-xs">
                              {item.type}
                            </Badge>
                          </TableCell>
                          <TableCell className="font-mono text-xs">{item.sku}</TableCell>
                          <TableCell className="text-sm">{item.name}</TableCell>
                          <TableCell>
                            {item.barcode ? (
                              <span className="font-mono text-xs">{item.barcode}</span>
                            ) : (
                              <Badge variant="warning" className="text-xs">
                                None
                              </Badge>
                            )}
                          </TableCell>
                          <TableCell className="text-right text-sm">
                            {formatCurrency(item.price)}
                          </TableCell>
                        </TableRow>
                      );
                    })
                  )}
                </TableBody>
              </Table>
            </div>

            {/* Generate button */}
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">
                {selectedCount} item{selectedCount !== 1 ? 's' : ''} selected
              </span>
              <Button onClick={handleGenerate} disabled={generating || selectedCount === 0}>
                <Barcode className="mr-1.5 h-4 w-4" />
                {generating
                  ? 'Generating...'
                  : `Generate ${selectedCount} Label${selectedCount !== 1 ? 's' : ''}`}
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Print preview */}
        {generatedLabels && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm">
                  Print Preview — {generatedLabels.count} label
                  {generatedLabels.count !== 1 ? 's' : ''} ({generatedLabels.label_size.label})
                </CardTitle>
                <div className="flex gap-2">
                  <Button onClick={handlePrint} size="sm">
                    <Printer className="mr-1.5 h-3.5 w-3.5" />
                    Print
                  </Button>
                  <Button onClick={() => setGeneratedLabels(null)} variant="ghost" size="sm">
                    Close
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <div
                ref={printRef}
                className="grid gap-2 print:gap-1"
                style={{
                  gridTemplateColumns: `repeat(${generatedLabels.label_size.cols}, 1fr)`,
                }}
              >
                {generatedLabels.labels.map((label, idx) => (
                  <div
                    key={idx}
                    className="break-inside-avoid rounded border border-muted-foreground/30 p-2 print:border-black print:border"
                    style={{
                      minHeight: `${generatedLabels.label_size.height * 2}px`,
                    }}
                  >
                    {label.show_name && label.name && (
                      <div className="truncate text-xs font-bold print:text-[8pt]">
                        {label.name}
                      </div>
                    )}
                    {label.show_sku && (
                      <div className="font-mono text-[10px] text-muted-foreground print:text-[7pt]">
                        {label.sku}
                      </div>
                    )}
                    {/* Barcode visual representation */}
                    <div className="my-1 flex h-10 items-center justify-center bg-white print:h-12">
                      <BarcodeVisual value={label.barcode} format={label.format} />
                    </div>
                    {label.show_text && (
                      <div className="text-center font-mono text-[10px] print:text-[7pt]">
                        {label.label_text}
                      </div>
                    )}
                    {label.show_price && label.price !== null && (
                      <div className="text-center text-xs font-bold print:text-[8pt]">
                        {formatCurrency(label.price)}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Print styles */}
      <style>{`
        @media print {
          body * { visibility: hidden; }
          ${printRef.current ? `#${printRef.current.id} *` : ''} { visibility: visible; }
          .no-print { display: none !important; }
        }
      `}</style>
    </AppLayout>
  );
}

function StatCard({
  label,
  value,
  sub,
  icon,
  accent,
}: {
  label: string;
  value: string;
  sub: string;
  icon: React.ReactNode;
  accent: 'info' | 'success' | 'warning' | 'destructive';
}) {
  const accentClass = {
    info: 'bg-info/10 text-info',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    destructive: 'bg-destructive/10 text-destructive',
  }[accent];

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <span className="text-xs font-medium uppercase text-muted-foreground">{label}</span>
          <span className={accentClass}>{icon}</span>
        </div>
        <div className="mt-2 text-xl font-bold">{value}</div>
        <div className="mt-0.5 text-xs text-muted-foreground">{sub}</div>
      </CardContent>
    </Card>
  );
}

/**
 * Simple barcode visual representation using CSS bars.
 * For production, use a library like JsBarcode or bwip-js.
 */
function BarcodeVisual({ value, format }: { value: string; format: string }) {
  if (format === 'QR') {
    // Simple QR placeholder — in production, use a QR library
    return (
      <div className="flex h-10 w-10 items-center justify-center border-2 border-black print:h-12 print:w-12">
        <span className="text-[8px] font-bold">QR</span>
      </div>
    );
  }

  // Generate pseudo-barcode bars from the value string
  const bars: number[] = [];
  for (let i = 0; i < value.length; i++) {
    const code = value.charCodeAt(i);
    bars.push((code % 4) + 1);
    bars.push(((code >> 2) % 3) + 1);
  }

  return (
    <div className="flex h-full w-full items-center justify-center gap-px overflow-hidden">
      {bars.map((width, idx) => (
        <div
          key={idx}
          className={idx % 2 === 0 ? 'bg-black' : 'bg-transparent'}
          style={{ width: `${width}px`, height: '100%' }}
        />
      ))}
    </div>
  );
}
