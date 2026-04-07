import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  ArrowLeft,
  Edit,
  Plus,
  Minus,
  AlertTriangle,
  RotateCcw,
  Sliders,
  Box,
} from 'lucide-react';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import type { Product, InventoryMovement, PaginatedResponse } from '@/types';

interface Props {
  product: Product;
  movements: PaginatedResponse<InventoryMovement>;
}

const movementConfig: Record<string, { label: string; icon: React.ReactNode; color: string }> = {
  STOCK_IN:    { label: 'Stock In',    icon: <Plus className="h-3.5 w-3.5" />,       color: 'bg-green-100 text-green-800' },
  STOCK_OUT:   { label: 'Stock Out',   icon: <Minus className="h-3.5 w-3.5" />,      color: 'bg-red-100 text-red-800' },
  ADJUSTMENT:  { label: 'Adjustment',  icon: <Sliders className="h-3.5 w-3.5" />,    color: 'bg-blue-100 text-blue-800' },
  RETURN:      { label: 'Return',      icon: <RotateCcw className="h-3.5 w-3.5" />,  color: 'bg-yellow-100 text-yellow-800' },
  RESERVATION: { label: 'Reserved',    icon: <Box className="h-3.5 w-3.5" />,         color: 'bg-purple-100 text-purple-800' },
  RELEASE:     { label: 'Released',    icon: <Box className="h-3.5 w-3.5" />,         color: 'bg-gray-100 text-gray-800' },
};

export default function ProductShow({ product, movements }: Props) {
  const [stockForm, setStockForm] = useState({ type: 'stock_in', quantity: '', notes: '' });
  const [submitting, setSubmitting] = useState(false);

  const stock = product.stock;
  const available = (stock?.current_stock ?? 0) - (stock?.reserved_stock ?? 0);
  const isLow = stock ? available <= stock.reorder_point : false;

  const handleStockAdjust = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    router.post(`/products/${product.id}/stock`, stockForm, {
      onFinish: () => {
        setSubmitting(false);
        setStockForm({ type: 'stock_in', quantity: '', notes: '' });
      },
      preserveScroll: true,
    });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/products">
              <Button variant="ghost" size="icon"><ArrowLeft className="h-4 w-4" /></Button>
            </Link>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-bold">{product.name}</h1>
                {!product.is_active && <Badge variant="outline">Inactive</Badge>}
                {isLow && <Badge variant="destructive"><AlertTriangle className="h-3 w-3 mr-1" />Low Stock</Badge>}
              </div>
              <p className="text-sm text-muted-foreground font-mono">{product.sku} {product.brand && `| ${product.brand}`} {product.category && `| ${product.category}`}</p>
            </div>
          </div>
          <Link href={`/products/${product.id}/edit`}>
            <Button variant="outline"><Edit className="mr-2 h-4 w-4" />Edit</Button>
          </Link>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Left — info + variants */}
          <div className="lg:col-span-2 space-y-6">
            {/* Stats row */}
            <div className="grid grid-cols-4 gap-4">
              <Card>
                <CardContent className="p-4 text-center">
                  <p className={`text-2xl font-bold ${isLow ? 'text-red-600' : ''}`}>{available}</p>
                  <p className="text-xs text-muted-foreground">Available</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-4 text-center">
                  <p className="text-2xl font-bold text-yellow-600">{stock?.reserved_stock ?? 0}</p>
                  <p className="text-xs text-muted-foreground">Reserved</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-4 text-center">
                  <p className="text-2xl font-bold">{formatCurrency(product.selling_price)}</p>
                  <p className="text-xs text-muted-foreground">Selling Price</p>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-4 text-center">
                  <p className="text-2xl font-bold text-green-600">{product.margin ?? 0}%</p>
                  <p className="text-xs text-muted-foreground">Margin</p>
                </CardContent>
              </Card>
            </div>

            {/* Variants */}
            {product.variants && product.variants.length > 0 && (
              <Card>
                <CardHeader><CardTitle className="text-base">Variants</CardTitle></CardHeader>
                <CardContent>
                  <div className="space-y-2">
                    {product.variants.map((v) => (
                      <div key={v.id} className="flex items-center justify-between p-3 rounded-lg border">
                        <div>
                          <p className="font-medium text-sm">{v.variant_name}</p>
                          <p className="text-xs text-muted-foreground font-mono">{v.sku}</p>
                        </div>
                        <div className="flex items-center gap-4 text-sm">
                          <span>{formatCurrency(v.selling_price ?? product.selling_price)}</span>
                          <span className="text-muted-foreground">Stock: {v.stock?.current_stock ?? 0}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Movement history */}
            <Card>
              <CardHeader><CardTitle className="text-base">Stock History</CardTitle></CardHeader>
              <CardContent>
                {movements.data.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-6">No inventory movements yet.</p>
                ) : (
                  <div className="space-y-2">
                    {movements.data.map((m) => {
                      const cfg = movementConfig[m.type] ?? { label: m.type, icon: <Box className="h-3.5 w-3.5" />, color: 'bg-gray-100 text-gray-800' };
                      return (
                        <div key={m.id} className="flex items-center justify-between p-3 rounded-lg border">
                          <div className="flex items-center gap-3">
                            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${cfg.color}`}>
                              {cfg.icon}{cfg.label}
                            </span>
                            <div>
                              {m.notes && <p className="text-xs text-muted-foreground">{m.notes}</p>}
                              <p className="text-[10px] text-muted-foreground">
                                {m.performer?.name ?? 'System'} | {formatDateTime(m.created_at)}
                              </p>
                            </div>
                          </div>
                          <p className={`font-mono font-semibold text-sm ${m.quantity >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {m.quantity >= 0 ? '+' : ''}{m.quantity}
                          </p>
                        </div>
                      );
                    })}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>

          {/* Right — stock adjustment */}
          <div className="space-y-6">
            <Card>
              <CardHeader><CardTitle className="text-base">Adjust Stock</CardTitle></CardHeader>
              <CardContent>
                <form onSubmit={handleStockAdjust} className="space-y-4">
                  <div>
                    <label className="block text-sm font-medium mb-1">Type</label>
                    <select
                      value={stockForm.type}
                      onChange={(e) => setStockForm((p) => ({ ...p, type: e.target.value }))}
                      className="w-full border rounded-lg px-3 py-2 text-sm"
                    >
                      <option value="stock_in">Stock In (restock)</option>
                      <option value="stock_out">Stock Out (manual)</option>
                      <option value="adjustment">Set Exact Quantity</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Quantity</label>
                    <input
                      type="number"
                      min="0"
                      value={stockForm.quantity}
                      onChange={(e) => setStockForm((p) => ({ ...p, quantity: e.target.value }))}
                      className="w-full border rounded-lg px-3 py-2 text-sm"
                      placeholder="0"
                      required
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Notes</label>
                    <input
                      type="text"
                      value={stockForm.notes}
                      onChange={(e) => setStockForm((p) => ({ ...p, notes: e.target.value }))}
                      className="w-full border rounded-lg px-3 py-2 text-sm"
                      placeholder="Reason for adjustment"
                    />
                  </div>
                  <Button type="submit" className="w-full" disabled={submitting}>
                    {submitting ? 'Updating...' : 'Update Stock'}
                  </Button>
                </form>
              </CardContent>
            </Card>

            {/* Product details */}
            <Card>
              <CardHeader><CardTitle className="text-base">Details</CardTitle></CardHeader>
              <CardContent className="space-y-2 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Cost Price</span>
                  <span>{formatCurrency(product.cost_price)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Weight</span>
                  <span>{product.weight_grams}g</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Reorder Point</span>
                  <span>{stock?.reorder_point ?? 10}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">QA Required</span>
                  <span>{product.requires_qa ? 'Yes' : 'No'}</span>
                </div>
                {product.description && (
                  <div className="pt-2 border-t">
                    <p className="text-muted-foreground">{product.description}</p>
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
