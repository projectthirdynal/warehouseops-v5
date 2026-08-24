import { Head, router } from '@inertiajs/react';
import { Plus, Search, Trash2, Power, Pencil, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface Promo {
  id: number;
  promo_code: string;
  name: string;
  description: string | null;
  type: string;
  trigger_quantity: number;
  free_quantity: number;
  discount_percentage: string;
  free_item_name: string | null;
  is_active: boolean;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string;
  product: { id: number; name: string } | null;
  free_product: { id: number; name: string } | null;
  creator: { id: number; name: string } | null;
}

interface PaginatedPromos {
  data: Promo[];
  current_page: number;
  last_page: number;
  total: number;
  from: number | null;
  to: number | null;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface PromoTypeOption {
  value: string;
  label: string;
  description: string;
}

interface Props {
  promos: PaginatedPromos;
  filters: { search?: string; active_only?: boolean };
  promoTypes: PromoTypeOption[];
}

const typeColors: Record<string, string> = {
  FREEBIE: 'bg-purple-100 text-purple-800',
  BUNDLE: 'bg-blue-100 text-blue-800',
  DISCOUNT: 'bg-green-100 text-green-800',
};

function getPromoSummary(promo: Promo): string {
  switch (promo.type) {
    case 'FREEBIE':
      return `Free ${promo.free_item_name || 'item'}`;
    case 'BUNDLE':
      return `Buy ${promo.trigger_quantity} Take ${promo.free_quantity}`;
    case 'DISCOUNT':
      return `${promo.discount_percentage}% off`;
    default:
      return '';
  }
}

export default function PromosIndex({ promos, filters, promoTypes }: Props) {
  const [search, setSearch] = useState(filters.search || '');
  const [activeOnly, setActiveOnly] = useState(filters.active_only || false);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.visit('/telesales/promos', {
      data: { search, active_only: activeOnly },
      preserveState: true,
      preserveScroll: true,
    });
  };

  const handleDelete = (promo: Promo) => {
    if (!confirm(`Delete promo "${promo.name}"?`)) return;
    fetch(`/api/telesales/promos/${promo.id}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN':
          document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
      },
    }).then(() => router.reload());
  };

  const handleToggle = (promo: Promo) => {
    fetch(`/api/telesales/promos/${promo.id}/toggle-active`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN':
          document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
      },
    }).then(() => router.reload());
  };

  return (
    <TelesalesLayout>
      <Head title="Promos & Freebies — Telesales" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Promos & Freebies</h1>
            <p className="text-sm text-muted-foreground mt-1">
              Manage freebie, bundle, and discount promos available to agents during order creation.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => router.visit('/telesales/promos/price-remarks')}
            >
              <FileSpreadsheet className="mr-2 h-4 w-4" />
              Price Remarks
            </Button>
            <Button onClick={() => router.visit('/telesales/promos/create')}>
              <Plus className="mr-2 h-4 w-4" />
              New Promo
            </Button>
          </div>
        </div>

        {/* Filters */}
        <form onSubmit={handleSearch} className="flex gap-3 items-center">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search promos..."
              className="pl-9"
            />
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => setActiveOnly(e.target.checked)}
              className="rounded"
            />
            Active only
          </label>
          <Button type="submit" variant="outline">
            Filter
          </Button>
        </form>

        {/* Promo Types Legend */}
        <div className="flex gap-3 text-xs text-muted-foreground">
          {promoTypes.map((t) => (
            <span key={t.value} className="flex items-center gap-1">
              <Badge className={typeColors[t.value]}>{t.label}</Badge>
              <span>{t.description}</span>
            </span>
          ))}
        </div>

        {/* Promos Table */}
        <Card>
          <CardHeader>
            <CardTitle>Promos ({promos.total})</CardTitle>
          </CardHeader>
          <CardContent>
            {promos.data.length === 0 ? (
              <p className="text-center text-muted-foreground py-8">
                No promos found. Click "New Promo" to create one.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-muted-foreground">
                      <th className="pb-2 pr-4">Code</th>
                      <th className="pb-2 pr-4">Name</th>
                      <th className="pb-2 pr-4">Type</th>
                      <th className="pb-2 pr-4">Summary</th>
                      <th className="pb-2 pr-4">Product</th>
                      <th className="pb-2 pr-4">Status</th>
                      <th className="pb-2 pr-4">Period</th>
                      <th className="pb-2 pr-4">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {promos.data.map((promo) => (
                      <tr key={promo.id} className="border-b hover:bg-muted/50">
                        <td className="py-3 pr-4 font-mono text-xs">{promo.promo_code}</td>
                        <td className="py-3 pr-4">
                          <div className="font-medium">{promo.name}</div>
                          {promo.description && (
                            <div className="text-xs text-muted-foreground">{promo.description}</div>
                          )}
                        </td>
                        <td className="py-3 pr-4">
                          <Badge className={typeColors[promo.type]}>{promo.type}</Badge>
                        </td>
                        <td className="py-3 pr-4">{getPromoSummary(promo)}</td>
                        <td className="py-3 pr-4 text-xs">
                          {promo.product?.name || 'Any product'}
                        </td>
                        <td className="py-3 pr-4">
                          <Badge
                            className={
                              promo.is_active
                                ? 'bg-green-100 text-green-800'
                                : 'bg-gray-100 text-gray-500'
                            }
                          >
                            {promo.is_active ? 'Active' : 'Inactive'}
                          </Badge>
                        </td>
                        <td className="py-3 pr-4 text-xs text-muted-foreground">
                          {promo.starts_at ? new Date(promo.starts_at).toLocaleDateString() : '—'}
                          {' → '}
                          {promo.ends_at ? new Date(promo.ends_at).toLocaleDateString() : '∞'}
                        </td>
                        <td className="py-3 pr-4">
                          <div className="flex gap-1">
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleToggle(promo)}
                              title={promo.is_active ? 'Deactivate' : 'Activate'}
                            >
                              <Power className="h-4 w-4" />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => router.visit(`/telesales/promos/${promo.id}/edit`)}
                              title="Edit"
                            >
                              <Pencil className="h-4 w-4" />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => handleDelete(promo)}
                              title="Delete"
                            >
                              <Trash2 className="h-4 w-4 text-red-500" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Pagination */}
            {promos.last_page > 1 && (
              <div className="flex items-center justify-between mt-4">
                <span className="text-sm text-muted-foreground">
                  Showing {promos.from}–{promos.to} of {promos.total}
                </span>
                <div className="flex gap-1">
                  {promos.links.map((link, i) => (
                    <Button
                      key={i}
                      size="sm"
                      variant={link.active ? 'default' : 'outline'}
                      disabled={!link.url}
                      onClick={() => link.url && router.visit(link.url)}
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </TelesalesLayout>
  );
}
