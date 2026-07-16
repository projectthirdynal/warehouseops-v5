import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import {
  AlertTriangle,
  ArrowLeft,
  Calculator,
  CheckCircle2,
  Eye,
  FileText,
  LayoutGrid,
  MapPinned,
  PackagePlus,
  Phone,
  Plus,
  RotateCcw,
  Sparkles,
  Trash2,
  Upload,
  User,
  UserPlus,
  X,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatCurrency } from '@/lib/utils';

interface ProductVariant {
  id: number;
  sku: string;
  variant_name: string;
  selling_price: string | number | null;
  available_stock?: number;
}

interface Product {
  id: number;
  sku: string;
  name: string;
  selling_price: string | number;
  available_stock?: number;
  active_variants: ProductVariant[];
}

interface Courier {
  value: string;
  label: string;
}

interface DuplicateWarning {
  id: number;
  order_number: string;
  status: string;
  total_amount: string | number;
  created_at: string;
  product?: { id: number; name: string; sku: string } | null;
}

interface CartItemForm {
  product_id: string;
  variant_id: string;
  quantity: string;
  unit_price: string;
  discount_amount: string;
}

interface OrderForm {
  customer_name: string;
  phone: string;
  normalized_phone?: string;
  customer_risk_level?: 'LOW' | 'MEDIUM' | 'HIGH' | 'BLACKLISTED' | null;
  customer_is_blacklisted?: boolean;
  complete_address: string;
  landmark: string;
  barangay: string;
  city_municipality: string;
  province: string;
  items: CartItemForm[];
  shipping_fee: string;
  discount_amount: string;
  tax_rate: string;
  courier_code: string;
  remarks: string;
  conversation_id: string;
  cod_amount: string;
  send_confirmation: boolean;
}

interface DraftSummary {
  id: number;
  order_number: string;
  customer_name: string;
  phone: string;
  created_at: string;
  items_count: number;
}

interface TemplateSummary {
  id: number;
  name: string;
  items: CartItemForm[];
  courier_code: string;
  shipping_fee: number;
  discount_amount: number;
  tax_rate: number;
  remarks: string | null;
  is_shared: boolean;
  is_owner: boolean;
  items_count: number;
  created_at: string;
}

interface RecommendedProduct {
  id: number;
  sku: string;
  name: string;
  selling_price: number;
}

interface Props {
  products: Product[];
  couriers: Courier[];
  prefill?: Partial<OrderForm> | null;
  duplicate_warnings: DuplicateWarning[];
  drafts: DraftSummary[];
  edit_order_id?: number | null;
  edit_order_number?: string | null;
}

function money(value: number) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(value);
}

function numeric(value: string | number | null | undefined) {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? parsed : 0;
}

function createEmptyItem(): CartItemForm {
  return {
    product_id: '',
    variant_id: '',
    quantity: '1',
    unit_price: '',
    discount_amount: '0',
  };
}

export default function CreateShopOrder({
  products,
  couriers,
  prefill,
  duplicate_warnings,
  drafts,
  edit_order_id = null,
  edit_order_number = null,
}: Props) {
  const { data, setData, post, put, processing, errors } = useForm<OrderForm>({
    customer_name: prefill?.customer_name ?? '',
    phone: prefill?.phone ?? '',
    normalized_phone: prefill?.normalized_phone ?? undefined,
    customer_risk_level: prefill?.customer_risk_level ?? null,
    customer_is_blacklisted: prefill?.customer_is_blacklisted ?? false,
    complete_address: prefill?.complete_address ?? '',
    landmark: prefill?.landmark ?? '',
    barangay: prefill?.barangay ?? '',
    city_municipality: prefill?.city_municipality ?? '',
    province: prefill?.province ?? '',
    items: prefill?.items && prefill.items.length > 0 ? prefill.items : [createEmptyItem()],
    shipping_fee: prefill?.shipping_fee ?? '0',
    discount_amount: prefill?.discount_amount ?? '0',
    tax_rate: prefill?.tax_rate ?? '0',
    courier_code: prefill?.courier_code ?? 'MANUAL',
    remarks: prefill?.remarks ?? '',
    conversation_id: prefill?.conversation_id ? String(prefill.conversation_id) : '',
    cod_amount: prefill?.cod_amount ?? '',
    send_confirmation: true,
  });

  const [customerLookup, setCustomerLookup] = useState<{
    status: 'idle' | 'searching' | 'found' | 'not_found';
    customer?: {
      id: number;
      name: string;
      phone: string;
      normalized_phone?: string;
      canonical_address?: string;
      risk_level?: string;
      is_blacklisted?: boolean;
      landmark?: string;
      barangay?: string;
      city_municipality?: string;
      province?: string;
      total_orders?: number;
      successful_orders?: number;
      returned_orders?: number;
      success_rate?: number;
    };
  }>({ status: 'idle' });
  const [orderHistory, setOrderHistory] = useState<
    Array<{
      id: number;
      order_number: string;
      status: string;
      total_amount: number;
      cod_amount: number;
      receiver_address: string;
      created_at: string;
      delivered_at: string | null;
      shop_items?: Array<{
        id: number;
        product_name: string;
        quantity: number;
        line_total: number;
      }>;
    }>
  >([]);
  const lookupTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const skipNextLookup = useRef(false);

  useEffect(() => {
    if (skipNextLookup.current) {
      skipNextLookup.current = false;
      return;
    }
    if (!data.phone || data.phone.length < 7) {
      setCustomerLookup({ status: 'idle' });
      return;
    }
    if (lookupTimer.current) clearTimeout(lookupTimer.current);
    setCustomerLookup({ status: 'searching' });
    lookupTimer.current = setTimeout(() => {
      axios
        .get('/shop/customers/search', { params: { q: data.phone, limit: 1 } })
        .then((res) => {
          const found = res.data.customers?.[0];
          if (found) {
            setCustomerLookup({ status: 'found', customer: found });
            skipNextLookup.current = true;
            setData({
              ...data,
              customer_name: found.name,
              normalized_phone: found.normalized_phone,
              customer_risk_level: found.risk_level as OrderForm['customer_risk_level'],
              customer_is_blacklisted: found.is_blacklisted,
              complete_address: found.canonical_address ?? data.complete_address,
              landmark: found.landmark ?? data.landmark,
              barangay: found.barangay ?? data.barangay,
              city_municipality: found.city_municipality ?? data.city_municipality,
              province: found.province ?? data.province,
            });
          } else {
            setCustomerLookup({ status: 'not_found' });
          }
        })
        .catch(() => setCustomerLookup({ status: 'idle' }));
    }, 500);
    return () => {
      if (lookupTimer.current) clearTimeout(lookupTimer.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data.phone]);

  useEffect(() => {
    if (customerLookup.status === 'found' && customerLookup.customer) {
      axios
        .get(`/shop/customers/${customerLookup.customer.id}/orders`)
        .then((res) => setOrderHistory(res.data.orders ?? []))
        .catch(() => setOrderHistory([]));
    } else {
      setOrderHistory([]);
    }
  }, [customerLookup.status, customerLookup.customer]);

  const itemError = (index: number, field: string) => {
    const key = `items.${index}.${field}` as keyof typeof errors;
    return errors[key];
  };

  const updateItem = (index: number, field: keyof CartItemForm, value: string) => {
    setData(
      'items',
      data.items.map((item, itemIndex) =>
        itemIndex === index ? { ...item, [field]: value } : item
      )
    );
  };

  const chooseProduct = (index: number, productId: string) => {
    const product = products.find((item) => String(item.id) === productId);

    setData(
      'items',
      data.items.map((item, itemIndex) =>
        itemIndex === index
          ? {
              ...item,
              product_id: productId,
              variant_id: '',
              unit_price: product ? String(product.selling_price) : '',
            }
          : item
      )
    );
  };

  const chooseVariant = (index: number, variantId: string) => {
    const currentItem = data.items[index];
    const selectedProduct = products.find(
      (product) => String(product.id) === currentItem.product_id
    );
    const variant = selectedProduct?.active_variants.find((item) => String(item.id) === variantId);

    setData(
      'items',
      data.items.map((item, itemIndex) =>
        itemIndex === index
          ? {
              ...item,
              variant_id: variantId,
              unit_price: variant?.selling_price ? String(variant.selling_price) : item.unit_price,
            }
          : item
      )
    );
  };

  const addItem = () => {
    setData('items', [...data.items, createEmptyItem()]);
  };

  const removeItem = (index: number) => {
    setData(
      'items',
      data.items.length === 1
        ? [createEmptyItem()]
        : data.items.filter((_, itemIndex) => itemIndex !== index)
    );
  };

  const subtotal = data.items.reduce(
    (total, item) =>
      total +
      Math.max(1, Number(item.quantity || 1)) * numeric(item.unit_price) -
      numeric(item.discount_amount),
    0
  );
  const totalQuantity = data.items.reduce(
    (total, item) => total + Math.max(1, Number(item.quantity || 1)),
    0
  );
  const shippingFee = numeric(data.shipping_fee);
  const orderDiscount = numeric(data.discount_amount);
  const taxRate = numeric(data.tax_rate);
  const taxableAmount = Math.max(0, subtotal - orderDiscount);
  const taxAmount = taxRate > 0 ? Math.round(taxableAmount * taxRate) / 100 : 0;
  const total = Math.max(0, taxableAmount + shippingFee + taxAmount);
  const codAmount = data.cod_amount ? numeric(data.cod_amount) : total;

  const [showPreview, setShowPreview] = useState(false);
  const [shippingZone, setShippingZone] = useState<string | null>(null);
  const [calculatingShipping, setCalculatingShipping] = useState(false);
  const [draftId, setDraftId] = useState<number | null>(null);
  const [savingDraft, setSavingDraft] = useState(false);
  const [draftList, setDraftList] = useState<DraftSummary[]>(drafts);
  const [liveDuplicates, setLiveDuplicates] = useState<DuplicateWarning[]>([]);

  const csrfToken =
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

  useEffect(() => {
    const phone = data.phone.trim();
    if (phone.length < 7) {
      setLiveDuplicates([]);
      return;
    }

    const productIds = data.items.map((item) => Number(item.product_id)).filter((id) => id > 0);

    const timer = setTimeout(() => {
      fetch('/shop/orders/check-duplicates', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ phone, product_ids: productIds }),
      })
        .then((res) => res.json())
        .then((result: { duplicates: DuplicateWarning[] }) => {
          setLiveDuplicates(result.duplicates ?? []);
        })
        .catch(() => undefined);
    }, 600);

    return () => clearTimeout(timer);
  }, [data.phone, data.items, csrfToken]);

  const allDuplicates = [...duplicate_warnings, ...liveDuplicates].filter(
    (dup, index, self) => index === self.findIndex((d) => d.id === dup.id)
  );

  const saveDraft = () => {
    setSavingDraft(true);
    fetch('/shop/orders/draft', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ ...data, draft_id: draftId }),
    })
      .then((res) => res.json())
      .then((result: { success: boolean; draft_id: number }) => {
        if (result.success) {
          setDraftId(result.draft_id);
        }
      })
      .catch(() => undefined)
      .finally(() => setSavingDraft(false));
  };

  const loadDraft = (id: number) => {
    fetch(`/shop/orders/${id}/draft`, { headers: { 'X-CSRF-TOKEN': csrfToken } })
      .then((res) => res.json())
      .then((result: { success: boolean; draft: Partial<OrderForm> }) => {
        if (result.success && result.draft) {
          const d = result.draft;
          setData({
            customer_name: d.customer_name ?? '',
            phone: d.phone ?? '',
            complete_address: d.complete_address ?? '',
            landmark: d.landmark ?? '',
            barangay: d.barangay ?? '',
            city_municipality: d.city_municipality ?? '',
            province: d.province ?? '',
            items: d.items && d.items.length > 0 ? d.items : [createEmptyItem()],
            shipping_fee: d.shipping_fee ?? '0',
            discount_amount: d.discount_amount ?? '0',
            tax_rate: d.tax_rate ?? '0',
            courier_code: d.courier_code ?? 'MANUAL',
            remarks: d.remarks ?? '',
            conversation_id: d.conversation_id ?? '',
            cod_amount: d.cod_amount ?? '',
            send_confirmation: false,
          });
          setDraftId(id);
        }
      })
      .catch(() => undefined);
  };

  const deleteDraft = (id: number) => {
    fetch(`/shop/orders/${id}/draft`, {
      method: 'DELETE',
      headers: { 'X-CSRF-TOKEN': csrfToken },
    })
      .then(() => {
        setDraftList((prev) => prev.filter((d) => d.id !== id));
        if (draftId === id) {
          setDraftId(null);
        }
      })
      .catch(() => undefined);
  };

  const [templates, setTemplates] = useState<TemplateSummary[]>([]);
  const [showTemplateModal, setShowTemplateModal] = useState(false);
  const [templateName, setTemplateName] = useState('');
  const [templateShared, setTemplateShared] = useState(false);
  const [savingTemplate, setSavingTemplate] = useState(false);

  const fetchTemplates = () => {
    fetch('/shop/templates', {
      headers: {
        'X-CSRF-TOKEN': csrfToken,
        Accept: 'application/json',
      },
    })
      .then((res) => res.json())
      .then((result) => {
        const raw = result.templates;
        const list = Array.isArray(raw) ? raw : (raw?.data ?? []);
        setTemplates(list);
      })
      .catch(() => undefined);
  };

  useEffect(() => {
    fetchTemplates();
  }, []);

  const saveTemplate = () => {
    if (!templateName.trim()) return;
    setSavingTemplate(true);
    fetch('/shop/templates', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({
        name: templateName.trim(),
        items: data.items,
        courier_code: data.courier_code,
        shipping_fee: data.shipping_fee,
        discount_amount: data.discount_amount,
        tax_rate: data.tax_rate,
        remarks: data.remarks,
        is_shared: templateShared,
      }),
    })
      .then((res) => res.json())
      .then(() => {
        setShowTemplateModal(false);
        setTemplateName('');
        setTemplateShared(false);
        fetchTemplates();
      })
      .catch(() => undefined)
      .finally(() => setSavingTemplate(false));
  };

  const applyTemplate = (tpl: TemplateSummary) => {
    setData({
      ...data,
      items: tpl.items.length > 0 ? tpl.items : [createEmptyItem()],
      courier_code: tpl.courier_code || 'MANUAL',
      shipping_fee: String(tpl.shipping_fee ?? 0),
      discount_amount: String(tpl.discount_amount ?? 0),
      tax_rate: String(tpl.tax_rate ?? 0),
      remarks: tpl.remarks ?? data.remarks,
    });
  };

  const deleteTemplate = (id: number) => {
    fetch(`/shop/templates/${id}`, {
      method: 'DELETE',
      headers: { 'X-CSRF-TOKEN': csrfToken },
    })
      .then(() => {
        setTemplates((prev) => prev.filter((t) => t.id !== id));
      })
      .catch(() => undefined);
  };

  const [showImportModal, setShowImportModal] = useState(false);
  const [importPreview, setImportPreview] = useState<{
    items: CartItemForm[];
    errors: string[];
  } | null>(null);

  const parseCsvLine = (line: string): string[] => {
    const result: string[] = [];
    let current = '';
    let inQuotes = false;
    for (let i = 0; i < line.length; i++) {
      const char = line[i];
      if (char === '"') {
        if (inQuotes && line[i + 1] === '"') {
          current += '"';
          i++;
        } else {
          inQuotes = !inQuotes;
        }
      } else if (char === ',' && !inQuotes) {
        result.push(current.trim());
        current = '';
      } else {
        current += char;
      }
    }
    result.push(current.trim());
    return result;
  };

  const handleCsvFile = (file: File) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const text = e.target?.result as string;
      if (!text) return;

      const lines = text.split(/\r?\n/).filter((l) => l.trim());
      if (lines.length < 2) {
        setImportPreview({ items: [], errors: ['CSV file is empty or has no data rows.'] });
        return;
      }

      const headers = parseCsvLine(lines[0]).map((h) => h.toLowerCase());
      const colIdx = (names: string[]) => headers.findIndex((h) => names.includes(h));

      const skuCol = colIdx(['sku', 'product_sku']);
      const idCol = colIdx(['product_id', 'id']);
      const variantSkuCol = colIdx(['variant_sku', 'variant']);
      const variantIdCol = colIdx(['variant_id']);
      const qtyCol = colIdx(['quantity', 'qty']);
      const priceCol = colIdx(['unit_price', 'price']);
      const discountCol = colIdx(['discount_amount', 'discount']);

      if (skuCol === -1 && idCol === -1) {
        setImportPreview({
          items: [],
          errors: ['CSV must have a "sku" or "product_id" column.'],
        });
        return;
      }

      const newItems: CartItemForm[] = [];
      const errors: string[] = [];

      for (let i = 1; i < lines.length; i++) {
        const cols = parseCsvLine(lines[i]);
        const sku = skuCol >= 0 ? cols[skuCol] : '';
        const productId = idCol >= 0 ? cols[idCol] : '';

        const product = products.find(
          (p) =>
            (sku && p.sku.toLowerCase() === sku.toLowerCase()) ||
            (productId && String(p.id) === productId)
        );

        if (!product) {
          errors.push(`Row ${i + 1}: Product "${sku || productId}" not found.`);
          continue;
        }

        let variantId = '';
        if (variantIdCol >= 0 && cols[variantIdCol]) {
          variantId = cols[variantIdCol];
        } else if (variantSkuCol >= 0 && cols[variantSkuCol]) {
          const variant = product.active_variants.find(
            (v) => v.sku.toLowerCase() === cols[variantSkuCol].toLowerCase()
          );
          if (variant) variantId = String(variant.id);
        }

        const quantity = qtyCol >= 0 ? cols[qtyCol] : '1';
        const unitPrice = priceCol >= 0 ? cols[priceCol] : String(product.selling_price);
        const discount = discountCol >= 0 ? cols[discountCol] : '0';

        if (!quantity || Number(quantity) < 1) {
          errors.push(`Row ${i + 1}: Invalid quantity "${quantity}".`);
          continue;
        }

        newItems.push({
          product_id: String(product.id),
          variant_id: variantId,
          quantity,
          unit_price: unitPrice,
          discount_amount: discount || '0',
        });
      }

      setImportPreview({ items: newItems, errors });
    };
    reader.readAsText(file);
  };

  const applyImport = () => {
    if (!importPreview || importPreview.items.length === 0) return;
    setData('items', importPreview.items);
    setShowImportModal(false);
    setImportPreview(null);
  };

  const [recommendations, setRecommendations] = useState<RecommendedProduct[]>([]);

  useEffect(() => {
    const productIds = data.items.map((item) => Number(item.product_id)).filter((id) => id > 0);
    if (productIds.length === 0) {
      setRecommendations([]);
      return;
    }
    const timer = setTimeout(() => {
      fetch('/shop/orders/recommendations', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ product_ids: productIds }),
      })
        .then((res) => res.json())
        .then((result: { recommendations: RecommendedProduct[] }) => {
          setRecommendations(result.recommendations ?? []);
        })
        .catch(() => undefined);
    }, 800);
    return () => clearTimeout(timer);
  }, [data.items]);

  const addRecommendation = (rec: RecommendedProduct) => {
    setData('items', [
      ...data.items,
      {
        product_id: String(rec.id),
        variant_id: '',
        quantity: '1',
        unit_price: String(rec.selling_price),
        discount_amount: '0',
      },
    ]);
  };

  const calculateShipping = () => {
    setCalculatingShipping(true);
    fetch('/shop/orders/calculate-shipping', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({
        province: data.province,
        city_municipality: data.city_municipality,
        barangay: data.barangay,
        address: data.complete_address,
        courier_code: data.courier_code,
      }),
    })
      .then((res) => res.json())
      .then((result: { fee: number; zone: string | null; has_rate: boolean }) => {
        setData('shipping_fee', result.fee.toFixed(2));
        setShippingZone(result.zone);
      })
      .catch(() => undefined)
      .finally(() => setCalculatingShipping(false));
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setShowPreview(true);
  };

  const confirmSubmit = () => {
    setShowPreview(false);
    if (edit_order_id) {
      put(`/shop/orders/${edit_order_id}`);
    } else {
      post('/shop/orders');
    }
  };

  return (
    <AppLayout>
      <Head title={edit_order_id ? `Edit ${edit_order_number}` : 'Create Shop Order'} />

      <form onSubmit={submit} className="space-y-4">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <Button asChild variant="ghost" size="sm" className="-ml-3 mb-2">
              <Link href="/shop">
                <ArrowLeft className="mr-1.5 h-4 w-4" />
                Shop
              </Link>
            </Button>
            <h1 className="text-xl font-bold tracking-tight font-display">
              {edit_order_id ? `Edit Order ${edit_order_number}` : 'Create Shop Order'}
            </h1>
            <p className="text-muted-foreground">
              {edit_order_id
                ? 'Update order details, items, and pricing'
                : data.conversation_id
                  ? `From Shop conversation #${data.conversation_id}`
                  : 'Manual POS entry for Facebook, chat, and phone orders'}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {draftId && (
              <Badge variant="secondary" className="text-xs">
                <FileText className="mr-1 h-3 w-3" />
                Draft #{draftId}
              </Badge>
            )}
            <Button type="button" variant="outline" onClick={saveDraft} disabled={savingDraft}>
              <FileText className="mr-1.5 h-4 w-4" />
              {savingDraft ? 'Saving...' : 'Save Draft'}
            </Button>
            <Button type="button" variant="outline" onClick={() => setShowTemplateModal(true)}>
              <LayoutGrid className="mr-1.5 h-4 w-4" />
              Save Template
            </Button>
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setImportPreview(null);
                setShowImportModal(true);
              }}
            >
              <Upload className="mr-1.5 h-4 w-4" />
              Import CSV
            </Button>
            <Button type="submit" disabled={processing}>
              <Eye className="mr-1.5 h-4 w-4" />
              Review Order
            </Button>
          </div>
        </div>

        {liveDuplicates.length > 0 && (
          <div className="flex items-center gap-2 rounded-md border border-warning/30 bg-warning/5 p-3 text-sm">
            <AlertTriangle className="h-4 w-4 shrink-0 text-warning" />
            <span className="text-muted-foreground">
              <span className="font-medium text-warning">
                {liveDuplicates.length} possible duplicate order
                {liveDuplicates.length > 1 ? 's' : ''}
              </span>{' '}
              found for this phone number in the last 30 days. Review before submitting.
            </span>
          </div>
        )}

        <div className="grid gap-6 xl:grid-cols-3">
          <div className="space-y-6 xl:col-span-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <User className="h-5 w-5" />
                  Customer
                </CardTitle>
                <CardDescription>Phone is normalized for customer matching</CardDescription>
              </CardHeader>
              <CardContent className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="customer_name">Customer name</Label>
                  <Input
                    id="customer_name"
                    value={data.customer_name}
                    onChange={(event) => setData('customer_name', event.target.value)}
                    placeholder="Maria Santos"
                  />
                  {errors.customer_name && (
                    <p className="text-xs text-destructive">{errors.customer_name}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="phone">Phone number</Label>
                  <Input
                    id="phone"
                    value={data.phone}
                    onChange={(event) => setData('phone', event.target.value)}
                    placeholder="09171234567"
                  />
                  {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
                  {data.normalized_phone && data.normalized_phone !== data.phone && (
                    <p className="text-xs text-muted-foreground">
                      Normalized: <span className="font-mono">{data.normalized_phone}</span>
                    </p>
                  )}
                  {data.customer_is_blacklisted && (
                    <div className="flex items-center gap-1.5 rounded-md border border-destructive/30 bg-destructive/5 px-2 py-1.5 text-xs text-destructive">
                      <AlertTriangle className="h-3.5 w-3.5" />
                      <span className="font-medium">Blacklisted customer</span>
                    </div>
                  )}
                  {!data.customer_is_blacklisted &&
                    data.customer_risk_level &&
                    data.customer_risk_level !== 'LOW' && (
                      <div
                        className={
                          'flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-xs ' +
                          (data.customer_risk_level === 'HIGH'
                            ? 'border-destructive/30 bg-destructive/5 text-destructive'
                            : 'border-warning/30 bg-warning/5 text-warning')
                        }
                      >
                        <AlertTriangle className="h-3.5 w-3.5" />
                        <span className="font-medium">
                          {data.customer_risk_level} risk customer
                        </span>
                      </div>
                    )}
                  {customerLookup.status === 'searching' && (
                    <p className="text-xs text-muted-foreground">Searching customers…</p>
                  )}
                  {customerLookup.status === 'found' && customerLookup.customer && (
                    <div className="rounded-md border border-success/30 bg-success/5 px-2 py-1.5 text-xs">
                      <div className="flex items-center gap-1.5 text-success">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        <span>
                          Existing customer:{' '}
                          <span className="font-medium">{customerLookup.customer.name}</span>
                          {customerLookup.customer.total_orders != null && (
                            <span className="text-muted-foreground">
                              {' '}
                              ({customerLookup.customer.total_orders} orders)
                            </span>
                          )}
                        </span>
                      </div>
                      {customerLookup.customer.total_orders != null &&
                        customerLookup.customer.total_orders > 0 && (
                          <div className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-muted-foreground">
                            <span>
                              Success:{' '}
                              <span className="font-medium text-success">
                                {customerLookup.customer.success_rate ?? 0}%
                              </span>
                            </span>
                            <span>
                              Returned:{' '}
                              <span className="font-medium text-destructive">
                                {customerLookup.customer.returned_orders ?? 0}
                              </span>
                            </span>
                            <span>
                              Return Rate:{' '}
                              <span className="font-medium">
                                {Math.round(
                                  ((customerLookup.customer.returned_orders ?? 0) /
                                    customerLookup.customer.total_orders) *
                                    100
                                )}
                                %
                              </span>
                            </span>
                          </div>
                        )}
                    </div>
                  )}
                  {customerLookup.status === 'not_found' && data.phone.length >= 7 && (
                    <div className="flex items-center gap-1.5 rounded-md border border-info/30 bg-info/5 px-2 py-1.5 text-xs text-info">
                      <UserPlus className="h-3.5 w-3.5" />
                      <span>No existing customer — a new one will be created on save.</span>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>

            {orderHistory.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2 text-base">
                    <PackagePlus className="h-5 w-5" />
                    Order History ({orderHistory.length})
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {orderHistory.map((ord) => (
                    <div
                      key={ord.id}
                      className="flex items-center justify-between rounded-md border p-2 text-sm"
                    >
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <Link
                            href={`/orders/${ord.id}`}
                            className="font-medium text-info hover:underline"
                          >
                            {ord.order_number}
                          </Link>
                          <Badge
                            variant="outline"
                            className={
                              'text-xs ' +
                              (ord.status === 'DELIVERED'
                                ? 'border-success/30 text-success'
                                : ord.status === 'CANCELLED' || ord.status === 'RETURNED'
                                  ? 'border-destructive/30 text-destructive'
                                  : 'border-muted text-muted-foreground')
                            }
                          >
                            {ord.status}
                          </Badge>
                        </div>
                        <p className="truncate text-xs text-muted-foreground">
                          {ord.shop_items
                            ?.map((si) => `${si.product_name} ×${si.quantity}`)
                            .join(', ') || '—'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {new Date(ord.created_at).toLocaleDateString()}
                        </p>
                      </div>
                      <div className="ml-2 text-right">
                        <p className="font-medium">{formatCurrency(ord.total_amount)}</p>
                        <p className="text-xs text-muted-foreground">
                          COD: {formatCurrency(ord.cod_amount)}
                        </p>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <MapPinned className="h-5 w-5" />
                  Delivery Address
                </CardTitle>
                <CardDescription>
                  Address mapping will use these fields for encoder review
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="complete_address">Complete address</Label>
                  <Textarea
                    id="complete_address"
                    value={data.complete_address}
                    onChange={(event) => setData('complete_address', event.target.value)}
                    placeholder="House number, street, barangay, city, province"
                  />
                  {errors.complete_address && (
                    <p className="text-xs text-destructive">{errors.complete_address}</p>
                  )}
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="landmark">Landmark</Label>
                    <Input
                      id="landmark"
                      value={data.landmark}
                      onChange={(event) => setData('landmark', event.target.value)}
                      placeholder="Near municipal hall"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="barangay">Barangay</Label>
                    <Input
                      id="barangay"
                      value={data.barangay}
                      onChange={(event) => setData('barangay', event.target.value)}
                      placeholder="San Roque"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="city_municipality">City / Municipality</Label>
                    <Input
                      id="city_municipality"
                      value={data.city_municipality}
                      onChange={(event) => setData('city_municipality', event.target.value)}
                      placeholder="Tarlac City"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="province">Province</Label>
                    <Input
                      id="province"
                      value={data.province}
                      onChange={(event) => setData('province', event.target.value)}
                      placeholder="Tarlac"
                    />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <div className="flex items-center justify-between gap-3">
                  <div>
                    <CardTitle className="flex items-center gap-2">
                      <PackagePlus className="h-5 w-5" />
                      Cart Items
                    </CardTitle>
                    <CardDescription>
                      Build one Shop order with multiple products and variants
                    </CardDescription>
                  </div>
                  <Button type="button" variant="outline" onClick={addItem}>
                    <Plus className="mr-1.5 h-4 w-4" />
                    Add Item
                  </Button>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                {data.items.map((item, index) => {
                  const selectedProduct = products.find(
                    (product) => String(product.id) === item.product_id
                  );
                  const selectedVariant = selectedProduct?.active_variants.find(
                    (variant) => String(variant.id) === item.variant_id
                  );
                  const quantity = Math.max(1, Number(item.quantity || 1));
                  const lineDiscount = numeric(item.discount_amount);
                  const lineTotal = Math.max(0, quantity * numeric(item.unit_price) - lineDiscount);
                  const availableStock = selectedVariant
                    ? (selectedVariant.available_stock ?? 0)
                    : (selectedProduct?.available_stock ?? 0);
                  const isOutOfStock = selectedProduct && availableStock <= 0;
                  const isInsufficient = selectedProduct && quantity > availableStock;

                  return (
                    <div key={index} className="rounded-lg border p-4">
                      <div className="mb-4 flex items-center justify-between gap-3">
                        <div>
                          <p className="text-sm font-medium">Item {index + 1}</p>
                          <p className="text-xs text-muted-foreground">
                            {selectedVariant?.variant_name ??
                              selectedProduct?.name ??
                              'Select a product'}
                          </p>
                        </div>
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          onClick={() => removeItem(index)}
                          disabled={data.items.length === 1}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>

                      <div className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2 md:col-span-2">
                          <Label htmlFor={`product_id_${index}`}>Product</Label>
                          <select
                            id={`product_id_${index}`}
                            value={item.product_id}
                            onChange={(event) => chooseProduct(index, event.target.value)}
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                          >
                            <option value="">Select product</option>
                            {products.map((product) => (
                              <option key={product.id} value={product.id}>
                                {product.name} ({product.sku})
                              </option>
                            ))}
                          </select>
                          {itemError(index, 'product_id') && (
                            <p className="text-xs text-destructive">
                              {itemError(index, 'product_id')}
                            </p>
                          )}
                          {selectedProduct && (
                            <p
                              className={`text-xs ${isOutOfStock ? 'text-destructive' : availableStock <= 5 ? 'text-amber-600' : 'text-muted-foreground'}`}
                            >
                              {isOutOfStock
                                ? 'Out of stock'
                                : `${availableStock} in stock${availableStock <= 5 ? ' (low)' : ''}`}
                            </p>
                          )}
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor={`variant_id_${index}`}>Variant</Label>
                          <select
                            id={`variant_id_${index}`}
                            value={item.variant_id}
                            onChange={(event) => chooseVariant(index, event.target.value)}
                            disabled={
                              !selectedProduct || selectedProduct.active_variants.length === 0
                            }
                            className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm disabled:opacity-50"
                          >
                            <option value="">Default</option>
                            {selectedProduct?.active_variants.map((variant) => (
                              <option key={variant.id} value={variant.id}>
                                {variant.variant_name} ({variant.sku})
                                {variant.available_stock !== undefined &&
                                  ` — ${variant.available_stock} in stock`}
                              </option>
                            ))}
                          </select>
                          {itemError(index, 'variant_id') && (
                            <p className="text-xs text-destructive">
                              {itemError(index, 'variant_id')}
                            </p>
                          )}
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor={`quantity_${index}`}>Quantity</Label>
                          <Input
                            id={`quantity_${index}`}
                            type="number"
                            min="1"
                            value={item.quantity}
                            onChange={(event) => updateItem(index, 'quantity', event.target.value)}
                          />
                          {itemError(index, 'quantity') && (
                            <p className="text-xs text-destructive">
                              {itemError(index, 'quantity')}
                            </p>
                          )}
                          {isInsufficient && (
                            <p className="text-xs text-destructive">
                              Only {availableStock} available — requested {quantity}
                            </p>
                          )}
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor={`unit_price_${index}`}>Unit price</Label>
                          <Input
                            id={`unit_price_${index}`}
                            type="number"
                            min="0"
                            step="0.01"
                            value={item.unit_price}
                            onChange={(event) =>
                              updateItem(index, 'unit_price', event.target.value)
                            }
                          />
                          {itemError(index, 'unit_price') && (
                            <p className="text-xs text-destructive">
                              {itemError(index, 'unit_price')}
                            </p>
                          )}
                        </div>

                        <div className="space-y-2">
                          <Label htmlFor={`discount_${index}`}>Line discount</Label>
                          <Input
                            id={`discount_${index}`}
                            type="number"
                            min="0"
                            step="0.01"
                            value={item.discount_amount}
                            onChange={(event) =>
                              updateItem(index, 'discount_amount', event.target.value)
                            }
                          />
                        </div>

                        <div className="flex items-end">
                          <div className="w-full rounded-lg bg-muted px-3 py-2 text-sm">
                            <div className="flex justify-between">
                              <span className="text-muted-foreground">Line total</span>
                              <span className="font-medium">{money(lineTotal)}</span>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </CardContent>
            </Card>
          </div>

          <div className="space-y-6">
            {allDuplicates.length > 0 && (
              <Card className="border-warning/20 bg-warning/5/50">
                <CardHeader>
                  <CardTitle className="flex items-center gap-2 text-warning">
                    <AlertTriangle className="h-5 w-5" />
                    Possible Duplicates
                  </CardTitle>
                  <CardDescription>
                    Recent Shop orders found for this phone number or products
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                  {allDuplicates.map((order) => (
                    <Link
                      key={order.id}
                      href={`/orders/${order.id}`}
                      className="block rounded-lg border bg-background p-3 text-sm transition-colors hover:bg-accent/30"
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium">{order.order_number}</p>
                          <p className="text-xs text-muted-foreground">
                            {order.product?.name ?? 'No product'}
                          </p>
                        </div>
                        <Badge variant="outline">{order.status}</Badge>
                      </div>
                      <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                        <span>{new Date(order.created_at).toLocaleString()}</span>
                        <span>{money(Number(order.total_amount ?? 0))}</span>
                      </div>
                    </Link>
                  ))}
                </CardContent>
              </Card>
            )}

            {draftList.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <FileText className="h-5 w-5" />
                    Saved Drafts
                  </CardTitle>
                  <CardDescription>Resume or delete draft orders</CardDescription>
                </CardHeader>
                <CardContent className="space-y-2">
                  {draftList.map((draft) => (
                    <div
                      key={draft.id}
                      className="flex items-center justify-between rounded-md border p-2 text-sm"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{draft.customer_name || 'Unnamed'}</p>
                        <p className="text-xs text-muted-foreground">
                          {draft.items_count} item(s) ·{' '}
                          {draft.created_at ? new Date(draft.created_at).toLocaleDateString() : ''}
                        </p>
                      </div>
                      <div className="flex items-center gap-1">
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => loadDraft(draft.id)}
                        >
                          <RotateCcw className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => deleteDraft(draft.id)}
                        >
                          <Trash2 className="h-3.5 w-3.5 text-destructive" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}

            {templates.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <LayoutGrid className="h-5 w-5" />
                    Cart Templates
                  </CardTitle>
                  <CardDescription>Apply a saved bundle to the cart</CardDescription>
                </CardHeader>
                <CardContent className="space-y-2">
                  {templates.map((tpl) => (
                    <div
                      key={tpl.id}
                      className="flex items-center justify-between rounded-md border p-2 text-sm"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{tpl.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {tpl.items_count} item(s)
                          {tpl.is_shared ? ' · Shared' : ''}
                        </p>
                      </div>
                      <div className="flex items-center gap-1">
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          onClick={() => applyTemplate(tpl)}
                        >
                          <RotateCcw className="h-3.5 w-3.5" />
                        </Button>
                        {tpl.is_owner && (
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => deleteTemplate(tpl.id)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-destructive" />
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}

            {recommendations.length > 0 && (
              <Card>
                <CardHeader>
                  <CardTitle className="flex items-center gap-2">
                    <Sparkles className="h-5 w-5" />
                    Frequently Bought Together
                  </CardTitle>
                  <CardDescription>Products commonly ordered with your cart items</CardDescription>
                </CardHeader>
                <CardContent className="space-y-2">
                  {recommendations.map((rec) => (
                    <div
                      key={rec.id}
                      className="flex items-center justify-between rounded-md border p-2 text-sm"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">{rec.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {rec.sku} · {money(rec.selling_price)}
                        </p>
                      </div>
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => addRecommendation(rec)}
                      >
                        <Plus className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Calculator className="h-5 w-5" />
                  Order Summary
                </CardTitle>
                <CardDescription>COD amount preview for the full cart</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  {data.items.map((item, index) => {
                    const selectedProduct = products.find(
                      (product) => String(product.id) === item.product_id
                    );
                    const selectedVariant = selectedProduct?.active_variants.find(
                      (variant) => String(variant.id) === item.variant_id
                    );
                    const quantity = Math.max(1, Number(item.quantity || 1));
                    const lineTotal = Math.max(
                      0,
                      quantity * numeric(item.unit_price) - numeric(item.discount_amount)
                    );

                    return (
                      <div key={index} className="rounded-lg border p-3">
                        <div className="flex justify-between gap-3 text-sm">
                          <div>
                            <p className="font-medium">
                              {selectedVariant?.variant_name ??
                                selectedProduct?.name ??
                                `Item ${index + 1}`}
                            </p>
                            <p className="text-xs text-muted-foreground">
                              {selectedVariant?.sku ?? selectedProduct?.sku ?? 'No SKU'} x{' '}
                              {quantity}
                            </p>
                          </div>
                          <span className="font-medium">{money(lineTotal)}</span>
                        </div>
                      </div>
                    );
                  })}
                </div>
                <div className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Total items</span>
                    <span>{totalQuantity}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Subtotal</span>
                    <span>{money(subtotal)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Shipping</span>
                    <span>{money(shippingFee)}</span>
                  </div>
                  {orderDiscount > 0 && (
                    <div className="flex justify-between text-destructive">
                      <span className="text-muted-foreground">Order discount</span>
                      <span>−{money(orderDiscount)}</span>
                    </div>
                  )}
                  {taxAmount > 0 && (
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Tax ({taxRate}%)</span>
                      <span>{money(taxAmount)}</span>
                    </div>
                  )}
                  <div className="flex justify-between border-t pt-3 text-base font-semibold">
                    <span>Computed Total</span>
                    <span>{money(total)}</span>
                  </div>
                  <div className="space-y-2 border-t pt-3">
                    <div className="flex items-center justify-between">
                      <Label htmlFor="cod_amount">COD Amount</Label>
                      {!data.cod_amount && (
                        <span className="text-xs text-muted-foreground">Auto from total</span>
                      )}
                    </div>
                    <Input
                      id="cod_amount"
                      type="number"
                      step="0.01"
                      min="0"
                      value={data.cod_amount}
                      onChange={(e) => setData('cod_amount', e.target.value)}
                      placeholder={total.toFixed(2)}
                    />
                    {data.cod_amount && Number(data.cod_amount) !== total && (
                      <p className="text-xs text-muted-foreground">
                        Override: {money(codAmount)} (computed: {money(total)})
                      </p>
                    )}
                    {errors.cod_amount && (
                      <p className="text-xs text-destructive">{errors.cod_amount}</p>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Phone className="h-5 w-5" />
                  Processing
                </CardTitle>
                <CardDescription>Initial order status is Confirmed</CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="shipping_fee">Shipping fee</Label>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={calculateShipping}
                      disabled={calculatingShipping || !data.province}
                    >
                      <Calculator className="mr-1 h-3.5 w-3.5" />
                      {calculatingShipping ? 'Calculating...' : 'Auto-calc'}
                    </Button>
                  </div>
                  <Input
                    id="shipping_fee"
                    type="number"
                    min="0"
                    step="0.01"
                    value={data.shipping_fee}
                    onChange={(event) => setData('shipping_fee', event.target.value)}
                  />
                  {shippingZone && (
                    <p className="text-xs text-muted-foreground">Zone: {shippingZone}</p>
                  )}
                  {errors.shipping_fee && (
                    <p className="text-xs text-destructive">{errors.shipping_fee}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="discount_amount">Order discount</Label>
                  <Input
                    id="discount_amount"
                    type="number"
                    min="0"
                    step="0.01"
                    value={data.discount_amount}
                    onChange={(event) => setData('discount_amount', event.target.value)}
                  />
                  {errors.discount_amount && (
                    <p className="text-xs text-destructive">{errors.discount_amount}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="tax_rate">Tax rate (%)</Label>
                  <Input
                    id="tax_rate"
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    value={data.tax_rate}
                    onChange={(event) => setData('tax_rate', event.target.value)}
                  />
                  {taxAmount > 0 && (
                    <p className="text-xs text-muted-foreground">Tax amount: {money(taxAmount)}</p>
                  )}
                  {errors.tax_rate && <p className="text-xs text-destructive">{errors.tax_rate}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="courier_code">Courier</Label>
                  <select
                    id="courier_code"
                    value={data.courier_code}
                    onChange={(event) => setData('courier_code', event.target.value)}
                    className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    {couriers.map((courier) => (
                      <option key={courier.value} value={courier.value}>
                        {courier.label}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="remarks">Remarks</Label>
                  <Textarea
                    id="remarks"
                    value={data.remarks}
                    onChange={(event) => setData('remarks', event.target.value)}
                    placeholder="Customer notes, product promise, delivery instruction"
                  />
                  {errors.remarks && <p className="text-xs text-destructive">{errors.remarks}</p>}
                </div>
                {data.conversation_id && (
                  <div className="flex items-center gap-2 rounded-md border p-3">
                    <input
                      id="send_confirmation"
                      type="checkbox"
                      checked={data.send_confirmation}
                      onChange={(e) => setData('send_confirmation', e.target.checked)}
                      className="h-4 w-4 rounded border-input"
                    />
                    <Label htmlFor="send_confirmation" className="text-sm font-normal">
                      Send order confirmation message to customer
                    </Label>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </form>

      {showPreview && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowPreview(false)}
        >
          <div
            className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-background p-6 shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="flex items-center gap-2 text-lg font-semibold">
                <Eye className="h-5 w-5" />
                Order Preview
              </h2>
              <button type="button" onClick={() => setShowPreview(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>

            <div className="space-y-4">
              {allDuplicates.length > 0 && (
                <div className="flex items-center gap-2 rounded-md border border-warning/30 bg-warning/5 p-3 text-sm">
                  <AlertTriangle className="h-4 w-4 shrink-0 text-warning" />
                  <span className="text-muted-foreground">
                    <span className="font-medium text-warning">
                      {allDuplicates.length} possible duplicate order
                      {allDuplicates.length > 1 ? 's' : ''}
                    </span>{' '}
                    found for this customer. Please review before confirming.
                  </span>
                </div>
              )}
              <div className="grid gap-4 md:grid-cols-2">
                <div className="rounded-md border p-3">
                  <p className="mb-1 text-xs font-medium text-muted-foreground">Customer</p>
                  <p className="text-sm font-medium">{data.customer_name || '—'}</p>
                  <p className="text-sm text-muted-foreground">{data.phone || '—'}</p>
                  {data.normalized_phone && data.normalized_phone !== data.phone && (
                    <p className="text-xs text-muted-foreground">
                      Normalized: <span className="font-mono">{data.normalized_phone}</span>
                    </p>
                  )}
                  {data.customer_is_blacklisted && (
                    <Badge variant="destructive" className="mt-1 text-xs">
                      Blacklisted
                    </Badge>
                  )}
                  {!data.customer_is_blacklisted &&
                    data.customer_risk_level &&
                    data.customer_risk_level !== 'LOW' && (
                      <Badge
                        variant="outline"
                        className={
                          'mt-1 text-xs ' +
                          (data.customer_risk_level === 'HIGH'
                            ? 'border-destructive/30 text-destructive'
                            : 'border-warning/30 text-warning')
                        }
                      >
                        {data.customer_risk_level} Risk
                      </Badge>
                    )}
                </div>
                <div className="rounded-md border p-3">
                  <p className="mb-1 text-xs font-medium text-muted-foreground">Delivery Address</p>
                  <p className="text-sm">{data.complete_address || '—'}</p>
                  {(data.barangay || data.city_municipality || data.province) && (
                    <p className="text-xs text-muted-foreground">
                      {[data.barangay, data.city_municipality, data.province]
                        .filter(Boolean)
                        .join(', ')}
                    </p>
                  )}
                </div>
              </div>

              <div className="rounded-md border">
                <table className="w-full text-sm">
                  <thead className="border-b bg-muted/50">
                    <tr>
                      <th className="px-3 py-2 text-left font-medium">Item</th>
                      <th className="px-3 py-2 text-right font-medium">Qty</th>
                      <th className="px-3 py-2 text-right font-medium">Price</th>
                      <th className="px-3 py-2 text-right font-medium">Disc</th>
                      <th className="px-3 py-2 text-right font-medium">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {data.items.map((item, index) => {
                      const product = products.find((p) => String(p.id) === item.product_id);
                      const variant = product?.active_variants.find(
                        (v) => String(v.id) === item.variant_id
                      );
                      const qty = Math.max(1, Number(item.quantity || 1));
                      const lineTotal = Math.max(
                        0,
                        qty * numeric(item.unit_price) - numeric(item.discount_amount)
                      );
                      return (
                        <tr key={index}>
                          <td className="px-3 py-2">
                            {variant?.variant_name ?? product?.name ?? `Item ${index + 1}`}
                            <p className="text-xs text-muted-foreground">
                              {variant?.sku ?? product?.sku ?? 'No SKU'}
                            </p>
                          </td>
                          <td className="px-3 py-2 text-right">{qty}</td>
                          <td className="px-3 py-2 text-right">
                            {money(numeric(item.unit_price))}
                          </td>
                          <td className="px-3 py-2 text-right text-destructive">
                            {numeric(item.discount_amount) > 0
                              ? `−${money(numeric(item.discount_amount))}`
                              : '—'}
                          </td>
                          <td className="px-3 py-2 text-right font-medium">{money(lineTotal)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              <div className="space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Total items</span>
                  <span>{totalQuantity}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span>{money(subtotal)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Shipping</span>
                  <span>{money(shippingFee)}</span>
                </div>
                {orderDiscount > 0 && (
                  <div className="flex justify-between text-destructive">
                    <span className="text-muted-foreground">Order discount</span>
                    <span>−{money(orderDiscount)}</span>
                  </div>
                )}
                {taxAmount > 0 && (
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Tax ({taxRate}%)</span>
                    <span>{money(taxAmount)}</span>
                  </div>
                )}
                <div className="flex justify-between border-t pt-2 text-base font-semibold">
                  <span>Computed Total</span>
                  <span>{money(total)}</span>
                </div>
                {codAmount !== total && (
                  <div className="flex justify-between rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-base font-semibold">
                    <span>COD Amount</span>
                    <span>{money(codAmount)}</span>
                  </div>
                )}
              </div>

              {data.courier_code && (
                <div className="rounded-md border p-3 text-sm">
                  <span className="text-muted-foreground">Courier: </span>
                  <span className="font-medium">
                    {couriers.find((c) => c.value === data.courier_code)?.label ??
                      data.courier_code}
                  </span>
                </div>
              )}

              {data.remarks && (
                <div className="rounded-md border p-3 text-sm">
                  <p className="mb-1 text-xs font-medium text-muted-foreground">Remarks</p>
                  <p className="whitespace-pre-wrap">{data.remarks}</p>
                </div>
              )}

              <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="outline" onClick={() => setShowPreview(false)}>
                  <X className="mr-1.5 h-4 w-4" />
                  Edit Order
                </Button>
                <Button type="button" onClick={confirmSubmit} disabled={processing}>
                  <CheckCircle2 className="mr-1.5 h-4 w-4" />
                  {edit_order_id ? 'Confirm & Update' : 'Confirm & Save'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}

      {showTemplateModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowTemplateModal(false)}
        >
          <div
            className="w-full max-w-md rounded-lg bg-background p-6 shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="flex items-center gap-2 text-lg font-semibold">
                <LayoutGrid className="h-5 w-5" />
                Save as Template
              </h2>
              <button type="button" onClick={() => setShowTemplateModal(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>

            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="template_name">Template name</Label>
                <Input
                  id="template_name"
                  value={templateName}
                  onChange={(e) => setTemplateName(e.target.value)}
                  placeholder="e.g. Bestseller Bundle"
                  autoFocus
                />
              </div>
              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="template_shared"
                  checked={templateShared}
                  onChange={(e) => setTemplateShared(e.target.checked)}
                  className="h-4 w-4 rounded border-input"
                />
                <Label htmlFor="template_shared" className="text-sm font-normal">
                  Share with other agents
                </Label>
              </div>
              <div className="rounded-md border p-3 text-xs text-muted-foreground">
                Saves {data.items.length} item(s) with current pricing, discounts, shipping, and tax
                settings.
              </div>
              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setShowTemplateModal(false)}>
                  Cancel
                </Button>
                <Button
                  type="button"
                  onClick={saveTemplate}
                  disabled={savingTemplate || !templateName.trim()}
                >
                  {savingTemplate ? 'Saving...' : 'Save Template'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}

      {showImportModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowImportModal(false)}
        >
          <div
            className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-lg bg-background p-6 shadow-lg"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="flex items-center gap-2 text-lg font-semibold">
                <Upload className="h-5 w-5" />
                Import Cart from CSV
              </h2>
              <button type="button" onClick={() => setShowImportModal(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>

            <div className="space-y-4">
              <div className="rounded-md border p-3 text-xs text-muted-foreground">
                <p className="mb-1 font-medium text-foreground">Expected columns:</p>
                <p>
                  sku (or product_id), variant_sku (or variant_id), quantity, unit_price,
                  discount_amount
                </p>
                <p className="mt-1">
                  Only sku/product_id and quantity are required. Others default to product settings.
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="csv_file">CSV file</Label>
                <Input
                  id="csv_file"
                  type="file"
                  accept=".csv,text/csv"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) handleCsvFile(file);
                  }}
                />
              </div>

              {importPreview && (
                <>
                  {importPreview.errors.length > 0 && (
                    <div className="rounded-md border border-warning/30 bg-warning/5 p-3">
                      <p className="mb-1 text-sm font-medium text-warning">
                        {importPreview.errors.length} warning(s)
                      </p>
                      <ul className="space-y-1 text-xs text-muted-foreground">
                        {importPreview.errors.map((err, i) => (
                          <li key={i}>{err}</li>
                        ))}
                      </ul>
                    </div>
                  )}

                  {importPreview.items.length > 0 && (
                    <div className="rounded-md border p-3">
                      <p className="mb-2 text-sm font-medium">
                        {importPreview.items.length} item(s) ready to import
                      </p>
                      <div className="max-h-40 space-y-1 overflow-y-auto text-xs">
                        {importPreview.items.map((item, i) => {
                          const product = products.find((p) => String(p.id) === item.product_id);
                          return (
                            <div key={i} className="flex justify-between">
                              <span className="truncate">
                                {product?.name ?? `Product #${item.product_id}`}
                              </span>
                              <span className="text-muted-foreground">x{item.quantity}</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  )}

                  {importPreview.items.length === 0 && importPreview.errors.length > 0 && (
                    <p className="text-sm text-destructive">No valid items found in CSV.</p>
                  )}
                </>
              )}

              <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={() => setShowImportModal(false)}>
                  Cancel
                </Button>
                <Button
                  type="button"
                  onClick={applyImport}
                  disabled={!importPreview || importPreview.items.length === 0}
                >
                  <CheckCircle2 className="mr-1.5 h-4 w-4" />
                  Add to Cart
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
