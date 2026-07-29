import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import {
  Search,
  ShoppingCart,
  Trash2,
  Plus,
  Minus,
  X,
  CreditCard,
  Receipt,
  CheckCircle2,
  Loader2,
  Package,
  Zap,
  RefreshCw,
} from 'lucide-react';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/* ─── Types ─── */

interface ProductVariant {
  id: number;
  sku: string;
  variant_name: string;
  selling_price: number;
  available_stock: number;
}

interface POSProduct {
  id: number;
  sku: string;
  name: string;
  brand: string | null;
  selling_price: number;
  image_url: string | null;
  available_stock: number;
  variants: ProductVariant[];
}

interface PaymentMethod {
  value: string;
  label: string;
}

interface CartItem {
  key: string;
  productId: number;
  variantId: number | null;
  name: string;
  sku: string;
  unitPrice: number;
  quantity: number;
  availableStock: number;
}

interface CheckoutResult {
  id: number;
  order_number: string;
  total_amount: number;
  change: number;
  payment_method: string;
  items_count: number;
}

interface Props {
  products: POSProduct[];
  payment_methods: PaymentMethod[];
}

/* ─── Helpers ─── */

function money(value: number) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 2,
  }).format(value);
}

function productKey(p: POSProduct, variantId: number | null) {
  return variantId ? `${p.id}-${variantId}` : `${p.id}`;
}

/* ─── Component ─── */

export default function POSIndex({ products, payment_methods }: Props) {
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<POSProduct[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [cart, setCart] = useState<CartItem[]>([]);
  const [checkoutOpen, setCheckoutOpen] = useState(false);
  const [processing, setProcessing] = useState(false);
  const [receipt, setReceipt] = useState<CheckoutResult | null>(null);

  // Checkout form state
  const [customerName, setCustomerName] = useState('');
  const [phone, setPhone] = useState('');
  const [paymentMethod, setPaymentMethod] = useState('CASH');
  const [discountAmount, setDiscountAmount] = useState('0');
  const [amountPaid, setAmountPaid] = useState('0');
  const [notes, setNotes] = useState('');

  // Cache status
  const [cacheStats, setCacheStats] = useState<{
    products_cached: boolean;
    products_count: number;
  } | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const fetchCacheStats = useCallback(async () => {
    try {
      const res = await fetch('/shop/pos/cache-stats', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (res.ok) {
        setCacheStats(await res.json());
      }
    } catch {
      /* ignore */
    }
  }, []);

  const refreshCache = useCallback(async () => {
    setRefreshing(true);
    try {
      const csrfMeta = document.querySelector('meta[name=csrf-token]') as HTMLMetaElement | null;
      const res = await fetch('/shop/pos/cache-clear', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrfMeta?.content ?? '',
        },
        credentials: 'same-origin',
      });
      if (res.ok) {
        const data = await res.json();
        toast.success(data.message || 'Cache cleared');
        apiCacheRef.current.clear();
        await fetchCacheStats();
      } else {
        toast.error('Failed to clear cache');
      }
    } catch {
      toast.error('Network error');
    } finally {
      setRefreshing(false);
    }
  }, [fetchCacheStats]);

  useEffect(() => {
    fetchCacheStats();
  }, [fetchCacheStats]);

  const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const lastQueryRef = useRef<string>('');
  const apiCacheRef = useRef<Map<string, POSProduct[]>>(new Map());

  /* ── Client-side filter for short queries ── */
  const clientFilter = useCallback(
    (q: string): POSProduct[] | null => {
      const query = q.trim().toLowerCase();
      if (query.length === 0) return null;
      if (query.length < 3) {
        const filtered = products.filter(
          (p) =>
            p.name.toLowerCase().includes(query) ||
            p.sku.toLowerCase().includes(query) ||
            (p.brand?.toLowerCase().includes(query) ?? false)
        );
        return filtered.length >= 5 ? filtered : null;
      }
      return null;
    },
    [products]
  );

  const displayedProducts = searchResults ?? products;

  /* ── Live search with debounce + client-side + API cache ── */
  const performSearch = useCallback(
    async (q: string) => {
      const query = q.trim();
      if (!query) {
        setSearchResults(null);
        lastQueryRef.current = '';
        return;
      }

      // Try client-side filter first for short queries
      const clientResults = clientFilter(query);
      if (clientResults !== null) {
        setSearchResults(clientResults);
        lastQueryRef.current = query;
        return;
      }

      // Check API response cache
      const cached = apiCacheRef.current.get(query);
      if (cached) {
        setSearchResults(cached);
        lastQueryRef.current = query;
        return;
      }

      setSearching(true);
      try {
        const res = await fetch(`/shop/pos/search?q=${encodeURIComponent(query)}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        if (res.ok) {
          const data = await res.json();
          const results = data.products as POSProduct[];
          apiCacheRef.current.set(query, results);
          setSearchResults(results);
          lastQueryRef.current = query;
        }
      } catch {
        // ignore — keep showing initial products
      } finally {
        setSearching(false);
      }
    },
    [clientFilter]
  );

  useEffect(() => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => performSearch(searchQuery), 350);
    return () => {
      if (searchTimer.current) clearTimeout(searchTimer.current);
    };
  }, [searchQuery, performSearch]);

  /* ── Cart operations ── */
  const addToCart = useCallback((product: POSProduct, variant?: ProductVariant) => {
    const vId = variant?.id ?? null;
    const key = productKey(product, vId);
    const price = variant?.selling_price ?? product.selling_price;
    const stock = variant?.available_stock ?? product.available_stock;
    const name = variant ? `${product.name} - ${variant.variant_name}` : product.name;
    const sku = variant?.sku ?? product.sku;

    setCart((prev) => {
      const existing = prev.find((i) => i.key === key);
      if (existing) {
        if (existing.quantity >= stock) {
          toast.warning('Cannot add more — insufficient stock');
          return prev;
        }
        return prev.map((i) => (i.key === key ? { ...i, quantity: i.quantity + 1 } : i));
      }
      return [
        ...prev,
        {
          key,
          productId: product.id,
          variantId: vId,
          name,
          sku,
          unitPrice: price,
          quantity: 1,
          availableStock: stock,
        },
      ];
    });
  }, []);

  const updateQty = useCallback((key: string, delta: number) => {
    setCart((prev) =>
      prev
        .map((i) => {
          if (i.key !== key) return i;
          const newQty = i.quantity + delta;
          if (newQty < 1) return i;
          if (newQty > i.availableStock) {
            toast.warning('Cannot exceed available stock');
            return i;
          }
          return { ...i, quantity: newQty };
        })
        .filter((i) => i.quantity > 0)
    );
  }, []);

  const setQty = useCallback((key: string, qty: number) => {
    setCart((prev) =>
      prev.map((i) => {
        if (i.key !== key) return i;
        const clamped = Math.max(1, Math.min(qty, i.availableStock));
        return { ...i, quantity: clamped };
      })
    );
  }, []);

  const removeFromCart = useCallback((key: string) => {
    setCart((prev) => prev.filter((i) => i.key !== key));
  }, []);

  const clearCart = useCallback(() => {
    setCart([]);
    setCustomerName('');
    setPhone('');
    setPaymentMethod('CASH');
    setDiscountAmount('0');
    setAmountPaid('0');
    setNotes('');
  }, []);

  /* ── Totals ── */
  const subtotal = useMemo(
    () => cart.reduce((sum, i) => sum + i.unitPrice * i.quantity, 0),
    [cart]
  );
  const discount = parseFloat(discountAmount) || 0;
  const total = Math.max(0, subtotal - discount);
  const paid = parseFloat(amountPaid) || 0;
  const change = Math.max(0, paid - total);
  const totalItems = useMemo(() => cart.reduce((s, i) => s + i.quantity, 0), [cart]);

  /* ── Checkout ── */
  const openCheckout = useCallback(() => {
    if (cart.length === 0) {
      toast.warning('Cart is empty');
      return;
    }
    setAmountPaid(total.toFixed(2));
    setCheckoutOpen(true);
  }, [cart.length, total]);

  const submitCheckout = useCallback(async () => {
    if (!customerName.trim()) {
      toast.error('Customer name is required');
      return;
    }
    if (paid < total && paymentMethod !== 'COD') {
      toast.error('Amount paid is less than total');
      return;
    }

    setProcessing(true);
    try {
      const csrfMeta = document.querySelector('meta[name=csrf-token]') as HTMLMetaElement | null;
      const res = await fetch('/shop/pos/checkout', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': csrfMeta?.content ?? '',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          customer_name: customerName,
          phone: phone || null,
          items: cart.map((i) => ({
            product_id: i.productId,
            variant_id: i.variantId,
            quantity: i.quantity,
            unit_price: i.unitPrice,
          })),
          payment_method: paymentMethod,
          discount_amount: discount > 0 ? discount : null,
          amount_paid: paymentMethod === 'COD' ? null : paid,
          notes: notes || null,
        }),
      });

      const data = await res.json();
      if (!res.ok) {
        const msg = data?.message ?? 'Checkout failed';
        toast.error(msg);
        return;
      }

      setReceipt(data.order as CheckoutResult);
      setCheckoutOpen(false);
      toast.success(`Order ${data.order.order_number} completed!`);
    } catch {
      toast.error('Network error — please try again');
    } finally {
      setProcessing(false);
    }
  }, [customerName, phone, cart, paymentMethod, discount, paid, total, notes]);

  const closeReceipt = useCallback(() => {
    setReceipt(null);
    clearCart();
  }, [clearCart]);

  /* ── Keyboard shortcut: F2 to checkout ── */
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'F2' && cart.length > 0 && !checkoutOpen && !receipt) {
        e.preventDefault();
        openCheckout();
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [cart.length, checkoutOpen, receipt, openCheckout]);

  /* ─── Render ─── */

  return (
    <AppLayout>
      <Head title="POS" />

      <div className="flex h-[calc(100vh-3.5rem)] flex-col gap-3 overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Point of Sale</h1>
            <p className="text-sm text-muted-foreground">In-store checkout and order processing</p>
          </div>
          <div className="flex items-center gap-2">
            {cacheStats && (
              <Badge variant="outline" className="gap-1 text-xs">
                <Zap className="h-3 w-3 text-success" />
                {cacheStats.products_cached ? `${cacheStats.products_count} cached` : 'No cache'}
              </Badge>
            )}
            <Button
              variant="ghost"
              size="sm"
              onClick={refreshCache}
              disabled={refreshing}
              title="Refresh product cache"
            >
              <RefreshCw className={cn('mr-1.5 h-3.5 w-3.5', refreshing && 'animate-spin')} />
              Refresh
            </Button>
            <Badge variant="secondary" className="tabular-nums">
              {totalItems} item{totalItems !== 1 ? 's' : ''}
            </Badge>
            {cart.length > 0 && (
              <Button variant="ghost" size="sm" onClick={clearCart}>
                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                Clear
              </Button>
            )}
            <Button size="sm" onClick={openCheckout} disabled={cart.length === 0}>
              <CreditCard className="mr-1.5 h-3.5 w-3.5" />
              Checkout
              <kbd className="ml-1.5 hidden rounded border border-primary-foreground/30 px-1 text-[10px] sm:inline">
                F2
              </kbd>
            </Button>
          </div>
        </div>

        {/* Main split: products | cart */}
        <div className="flex flex-1 gap-3 overflow-hidden">
          {/* Left: Product search + grid */}
          <div className="flex flex-1 flex-col gap-3 overflow-hidden">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search products by name, SKU, or brand..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoFocus
              />
              {searching && (
                <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground" />
              )}
            </div>

            <div className="flex-1 overflow-y-auto rounded-xl border bg-card p-3">
              {displayedProducts.length === 0 ? (
                <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
                  <Package className="mb-2 h-10 w-10 opacity-40" />
                  <p className="text-sm">
                    {searchQuery ? 'No products found' : 'No products available'}
                  </p>
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                  {displayedProducts.map((product) => (
                    <ProductCard
                      key={product.id}
                      product={product}
                      onAdd={(variant) => addToCart(product, variant)}
                    />
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Right: Cart panel */}
          <div className="flex w-[380px] shrink-0 flex-col overflow-hidden rounded-xl border bg-card">
            <div className="flex items-center gap-2 border-b px-4 py-3">
              <ShoppingCart className="h-4 w-4 text-primary" />
              <span className="text-sm font-semibold">Cart</span>
              {cart.length > 0 && (
                <Badge variant="secondary" className="ml-auto tabular-nums">
                  {totalItems}
                </Badge>
              )}
            </div>

            <div className="flex-1 overflow-y-auto p-3">
              {cart.length === 0 ? (
                <div className="flex h-full flex-col items-center justify-center text-muted-foreground">
                  <ShoppingCart className="mb-2 h-10 w-10 opacity-30" />
                  <p className="text-sm">Cart is empty</p>
                  <p className="text-xs">Click a product to add it</p>
                </div>
              ) : (
                <div className="space-y-2">
                  {cart.map((item) => (
                    <div key={item.key} className="rounded-lg border bg-background p-2.5">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{item.name}</p>
                          <p className="text-xs text-muted-foreground">{item.sku}</p>
                        </div>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-6 w-6 shrink-0 text-muted-foreground hover:text-destructive"
                          onClick={() => removeFromCart(item.key)}
                        >
                          <X className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                      <div className="mt-2 flex items-center justify-between">
                        <div className="flex items-center gap-1">
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7"
                            onClick={() => updateQty(item.key, -1)}
                          >
                            <Minus className="h-3 w-3" />
                          </Button>
                          <Input
                            type="number"
                            min={1}
                            max={item.availableStock}
                            value={item.quantity}
                            onChange={(e) => setQty(item.key, parseInt(e.target.value) || 1)}
                            className="h-7 w-12 text-center tabular-nums [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                          />
                          <Button
                            variant="outline"
                            size="icon"
                            className="h-7 w-7"
                            onClick={() => updateQty(item.key, 1)}
                            disabled={item.quantity >= item.availableStock}
                          >
                            <Plus className="h-3 w-3" />
                          </Button>
                        </div>
                        <div className="text-right">
                          <p className="text-sm font-semibold tabular-nums">
                            {money(item.unitPrice * item.quantity)}
                          </p>
                          <p className="text-[10px] text-muted-foreground tabular-nums">
                            {money(item.unitPrice)} ea
                          </p>
                        </div>
                      </div>
                      {item.availableStock <= 5 && (
                        <p className="mt-1 text-[10px] text-warning">
                          Only {item.availableStock} left in stock
                        </p>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>

            {/* Cart totals */}
            {cart.length > 0 && (
              <div className="border-t p-3 space-y-1.5">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Subtotal</span>
                  <span className="tabular-nums">{money(subtotal)}</span>
                </div>
                {discount > 0 && (
                  <div className="flex justify-between text-sm text-success">
                    <span>Discount</span>
                    <span className="tabular-nums">-{money(discount)}</span>
                  </div>
                )}
                <div className="flex justify-between border-t pt-1.5 text-base font-bold">
                  <span>Total</span>
                  <span className="tabular-nums">{money(total)}</span>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Checkout Dialog */}
      <Dialog open={checkoutOpen} onOpenChange={(o) => !processing && setCheckoutOpen(o)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <CreditCard className="h-5 w-5 text-primary" />
              Checkout
            </DialogTitle>
            <DialogDescription>
              Complete the sale — {totalItems} item{totalItems !== 1 ? 's' : ''} · {money(total)}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="col-span-2 space-y-1.5">
                <Label htmlFor="cust-name">Customer Name *</Label>
                <Input
                  id="cust-name"
                  value={customerName}
                  onChange={(e) => setCustomerName(e.target.value)}
                  placeholder="Walk-in customer"
                  autoFocus
                />
              </div>
              <div className="col-span-2 space-y-1.5">
                <Label htmlFor="cust-phone">Phone (optional)</Label>
                <Input
                  id="cust-phone"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="09XX XXX XXXX"
                />
              </div>
              <div className="space-y-1.5">
                <Label>Payment Method</Label>
                <Select value={paymentMethod} onValueChange={setPaymentMethod}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {payment_methods.map((m) => (
                      <SelectItem key={m.value} value={m.value}>
                        {m.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="discount">Discount (₱)</Label>
                <Input
                  id="discount"
                  type="number"
                  min={0}
                  step="0.01"
                  value={discountAmount}
                  onChange={(e) => setDiscountAmount(e.target.value)}
                  className="tabular-nums"
                />
              </div>
              {paymentMethod !== 'COD' && (
                <div className="col-span-2 space-y-1.5">
                  <Label htmlFor="amount-paid">Amount Paid (₱)</Label>
                  <Input
                    id="amount-paid"
                    type="number"
                    min={0}
                    step="0.01"
                    value={amountPaid}
                    onChange={(e) => setAmountPaid(e.target.value)}
                    className="tabular-nums text-lg font-semibold"
                  />
                  {paid >= total && change > 0 && (
                    <p className="text-sm text-success">
                      Change: <span className="font-bold tabular-nums">{money(change)}</span>
                    </p>
                  )}
                </div>
              )}
              <div className="col-span-2 space-y-1.5">
                <Label htmlFor="notes">Notes (optional)</Label>
                <Input
                  id="notes"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Special instructions..."
                />
              </div>
            </div>

            {/* Summary */}
            <div className="rounded-lg border bg-muted/50 p-3 space-y-1 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Subtotal</span>
                <span className="tabular-nums">{money(subtotal)}</span>
              </div>
              {discount > 0 && (
                <div className="flex justify-between text-success">
                  <span>Discount</span>
                  <span className="tabular-nums">-{money(discount)}</span>
                </div>
              )}
              <div className="flex justify-between border-t pt-1 font-bold">
                <span>Total</span>
                <span className="tabular-nums">{money(total)}</span>
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setCheckoutOpen(false)} disabled={processing}>
              Cancel
            </Button>
            <Button onClick={submitCheckout} disabled={processing}>
              {processing ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Processing...
                </>
              ) : (
                <>
                  <CheckCircle2 className="mr-2 h-4 w-4" />
                  Complete Sale
                </>
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Receipt Dialog */}
      <Dialog open={!!receipt} onOpenChange={(o) => !o && closeReceipt()}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Receipt className="h-5 w-5 text-primary" />
              Sale Complete
            </DialogTitle>
          </DialogHeader>

          {receipt && (
            <div className="space-y-4 py-2">
              <div className="flex flex-col items-center text-center">
                <CheckCircle2 className="h-12 w-12 text-success" />
                <p className="mt-2 text-lg font-bold">{receipt.order_number}</p>
                <p className="text-sm text-muted-foreground">
                  {receipt.items_count} item{receipt.items_count !== 1 ? 's' : ''} ·{' '}
                  {payment_methods.find((m) => m.value === receipt.payment_method)?.label}
                </p>
              </div>

              <div className="rounded-lg border bg-muted/50 p-3 space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Total Amount</span>
                  <span className="font-semibold tabular-nums">{money(receipt.total_amount)}</span>
                </div>
                {receipt.change > 0 && (
                  <div className="flex justify-between text-success">
                    <span>Change</span>
                    <span className="font-bold tabular-nums">{money(receipt.change)}</span>
                  </div>
                )}
              </div>

              <Button className="w-full" onClick={closeReceipt}>
                New Sale
              </Button>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

/* ─── Product Card ─── */

function ProductCard({
  product,
  onAdd,
}: {
  product: POSProduct;
  onAdd: (variant?: ProductVariant) => void;
}) {
  const [showVariants, setShowVariants] = useState(false);
  const hasVariants = product.variants.length > 0;
  const outOfStock =
    product.available_stock === 0 && product.variants.every((v) => v.available_stock === 0);

  return (
    <div
      className={cn(
        'group relative flex flex-col rounded-lg border bg-background p-2.5 transition-all hover:border-primary hover:shadow-sm',
        outOfStock && 'opacity-50'
      )}
    >
      <button
        className="flex flex-1 flex-col text-left"
        onClick={() => {
          if (outOfStock) return;
          if (hasVariants) {
            setShowVariants((v) => !v);
          } else {
            onAdd();
          }
        }}
        disabled={outOfStock}
      >
        <div className="mb-2 flex h-16 items-center justify-center rounded-md bg-muted">
          {product.image_url ? (
            <img
              src={product.image_url}
              alt={product.name}
              className="h-full w-full rounded-md object-cover"
            />
          ) : (
            <Package className="h-6 w-6 text-muted-foreground" />
          )}
        </div>
        <p className="line-clamp-2 text-xs font-medium leading-tight">{product.name}</p>
        {product.brand && <p className="text-[10px] text-muted-foreground">{product.brand}</p>}
        <div className="mt-1 flex items-center justify-between">
          <span className="text-sm font-bold text-primary tabular-nums">
            {money(product.selling_price)}
          </span>
          {!hasVariants && (
            <span
              className={cn(
                'text-[10px] tabular-nums',
                product.available_stock === 0
                  ? 'text-destructive'
                  : product.available_stock <= 5
                    ? 'text-warning'
                    : 'text-muted-foreground'
              )}
            >
              {product.available_stock} left
            </span>
          )}
        </div>
      </button>

      {hasVariants && showVariants && (
        <div className="mt-2 space-y-1 border-t pt-2">
          {product.variants.map((v) => (
            <button
              key={v.id}
              className="flex w-full items-center justify-between rounded-md border bg-card px-2 py-1.5 text-xs hover:border-primary"
              onClick={() => {
                onAdd(v);
                setShowVariants(false);
              }}
              disabled={v.available_stock === 0}
            >
              <span className="font-medium">{v.variant_name}</span>
              <div className="flex items-center gap-2">
                <span className="font-bold text-primary tabular-nums">
                  {money(v.selling_price)}
                </span>
                <span
                  className={cn(
                    'text-[10px] tabular-nums',
                    v.available_stock === 0
                      ? 'text-destructive'
                      : v.available_stock <= 5
                        ? 'text-warning'
                        : 'text-muted-foreground'
                  )}
                >
                  {v.available_stock}
                </span>
              </div>
            </button>
          ))}
        </div>
      )}

      {hasVariants && !showVariants && (
        <Button
          variant="ghost"
          size="xs"
          className="mt-1.5 w-full"
          onClick={() => setShowVariants(true)}
          disabled={outOfStock}
        >
          {product.variants.length} variant{product.variants.length !== 1 ? 's' : ''}
        </Button>
      )}
    </div>
  );
}
