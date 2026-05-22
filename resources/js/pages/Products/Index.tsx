import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  Package,
  Plus,
  Search,
  AlertTriangle,
  Box,
  TrendingUp,
  ChevronRight,
  Archive,
} from 'lucide-react';
import { formatCurrency } from '@/lib/utils';
import type { Product, PaginatedResponse } from '@/types';

interface Props {
  products: PaginatedResponse<Product>;
  stats: {
    total: number;
    active: number;
    low_stock: number;
    categories: string[];
  };
  filters: {
    search?: string;
    category?: string;
    status?: string;
    stock?: string;
  };
}

export default function ProductsIndex({ products, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.search || '');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.get('/products', { ...filters, search }, { preserveState: true });
  };

  const setFilter = (key: string, value: string | undefined) => {
    router.get('/products', { ...filters, [key]: value, search: filters.search }, { preserveState: true });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Products & Inventory</h1>
            <p className="text-sm text-muted-foreground">Manage product catalog and stock levels</p>
          </div>
          <Link href="/products/create">
            <Button>
              <Plus className="mr-2 h-4 w-4" />
              Add Product
            </Button>
          </Link>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-blue-100">
                  <Package className="h-5 w-5 text-blue-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.total}</p>
                  <p className="text-xs text-muted-foreground">Total Products</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-green-100">
                  <Box className="h-5 w-5 text-green-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.active}</p>
                  <p className="text-xs text-muted-foreground">Active</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-red-100">
                  <AlertTriangle className="h-5 w-5 text-red-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.low_stock}</p>
                  <p className="text-xs text-muted-foreground">Low Stock</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="p-2 rounded-lg bg-purple-100">
                  <TrendingUp className="h-5 w-5 text-purple-600" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{stats.categories.length}</p>
                  <p className="text-xs text-muted-foreground">Categories</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-3">
          <form onSubmit={handleSearch} className="flex-1 min-w-[200px] max-w-sm">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Search products..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full pl-9 pr-4 py-2 border rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/20"
              />
            </div>
          </form>

          <select
            value={filters.category || ''}
            onChange={(e) => setFilter('category', e.target.value || undefined)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Categories</option>
            {stats.categories.map((cat) => (
              <option key={cat} value={cat}>{cat}</option>
            ))}
          </select>

          <select
            value={filters.stock || ''}
            onChange={(e) => setFilter('stock', e.target.value || undefined)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Stock</option>
            <option value="low">Low Stock</option>
          </select>

          <select
            value={filters.status || ''}
            onChange={(e) => setFilter('status', e.target.value || undefined)}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>

        {/* Product list */}
        <div className="space-y-2">
          {products.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <Package className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p className="font-medium">No products found</p>
                <p className="text-sm">Add your first product to get started.</p>
              </CardContent>
            </Card>
          ) : (
            products.data.map((product) => {
              const stock = product.stock;
              const available = (stock?.current_stock ?? 0) - (stock?.reserved_stock ?? 0);
              const isLow = stock ? available <= stock.reorder_point : false;

              return (
                <Link key={product.id} href={`/products/${product.id}`} className="block">
                  <Card className="hover:bg-accent/30 transition-colors cursor-pointer">
                    <CardContent className="p-4">
                      <div className="flex items-center gap-4">
                        {/* Product icon / image */}
                        <div className="h-12 w-12 rounded-lg bg-muted flex items-center justify-center shrink-0">
                          {product.image_url ? (
                            <img src={product.image_url} alt="" className="h-10 w-10 object-contain rounded" />
                          ) : (
                            <Package className="h-6 w-6 text-muted-foreground" />
                          )}
                        </div>

                        {/* Info */}
                        <div className="flex-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <h3 className="font-semibold truncate">{product.name}</h3>
                            {!product.is_active && (
                              <Badge variant="outline" className="text-muted-foreground">
                                <Archive className="h-3 w-3 mr-1" />Inactive
                              </Badge>
                            )}
                            {isLow && (
                              <Badge variant="destructive" className="text-[10px]">
                                <AlertTriangle className="h-3 w-3 mr-1" />Low Stock
                              </Badge>
                            )}
                          </div>
                          <div className="flex items-center gap-3 text-xs text-muted-foreground mt-0.5">
                            <span className="font-mono">{product.sku}</span>
                            {product.brand && <span>{product.brand}</span>}
                            {product.category && <Badge variant="outline" className="text-[10px]">{product.category}</Badge>}
                          </div>
                        </div>

                        {/* Stock */}
                        <div className="text-center px-4 shrink-0">
                          <p className={`text-lg font-bold ${isLow ? 'text-red-600' : 'text-foreground'}`}>
                            {available}
                          </p>
                          <p className="text-[10px] text-muted-foreground uppercase">Available</p>
                          {(stock?.reserved_stock ?? 0) > 0 && (
                            <p className="text-[10px] text-yellow-600">{stock!.reserved_stock} reserved</p>
                          )}
                        </div>

                        {/* Price */}
                        <div className="text-right shrink-0">
                          <p className="font-semibold">{formatCurrency(product.selling_price)}</p>
                          <p className="text-xs text-muted-foreground">Cost: {formatCurrency(product.cost_price)}</p>
                        </div>

                        <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0" />
                      </div>
                    </CardContent>
                  </Card>
                </Link>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {products.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: products.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === products.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => router.get('/products', { ...filters, page })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
