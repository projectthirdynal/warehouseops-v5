import { useState, useMemo } from 'react';
import { Head, router } from '@inertiajs/react';
import { Package, MapPin, Calendar, TrendingUp, Layers } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Button } from '@/components/ui/button';

interface InventoryRow {
  brand: string;
  region: string;
  age_0_7: number;
  age_8_30: number;
  age_31_60: number;
  total: number;
}

interface InventorySummary {
  total_eligible: number;
  by_brand: Record<string, number>;
  by_region: Record<string, number>;
}

interface FilterOptions {
  brands: Array<{ id: string; name: string }>;
  regions: string[];
  provinces: string[];
  sources: string[];
}

interface Props {
  breakdown: InventoryRow[];
  summary: InventorySummary;
  filterOptions: FilterOptions;
  filters: {
    business_region?: string;
    province?: string;
    city?: string;
  };
  maxWaybillAgeDays: number;
}

export default function LeadInventoryIndex({
  breakdown,
  summary,
  filterOptions,
  filters,
  maxWaybillAgeDays,
}: Props) {
  const [regionFilter, setRegionFilter] = useState<string>(filters.business_region ?? 'all');
  const [provinceFilter, setProvinceFilter] = useState<string>(filters.province ?? 'all');

  const applyFilters = (region: string, province: string) => {
    const params: Record<string, string> = {};
    if (region !== 'all') params.business_region = region;
    if (province !== 'all') params.province = province;
    router.get('/telesales/inventory', params, { preserveState: true, preserveScroll: true });
  };

  const handleRegionChange = (value: string) => {
    setRegionFilter(value);
    setProvinceFilter('all');
    applyFilters(value, 'all');
  };

  const handleProvinceChange = (value: string) => {
    setProvinceFilter(value);
    applyFilters(regionFilter, value);
  };

  const filteredBreakdown = useMemo(() => {
    return breakdown;
  }, [breakdown]);

  const grandTotal = useMemo(() => {
    return filteredBreakdown.reduce((sum, row) => sum + row.total, 0);
  }, [filteredBreakdown]);

  const formatNumber = (n: number) => n.toLocaleString();

  return (
    <TelesalesLayout>
      <Head title="Lead Inventory — Telesales" />

      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Lead Inventory</h1>
            <p className="text-sm text-muted-foreground mt-1">
              Eligible leads available for telesales pooling. Max waybill age: {maxWaybillAgeDays}{' '}
              days.
            </p>
          </div>
          <Button variant="outline" onClick={() => router.reload()}>
            <TrendingUp className="mr-2 h-4 w-4" />
            Refresh
          </Button>
        </div>

        {/* Summary Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">Total Eligible</CardTitle>
              <Layers className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{formatNumber(summary.total_eligible)}</div>
            </CardContent>
          </Card>
          {Object.entries(summary.by_brand)
            .slice(0, 3)
            .map(([brand, count]) => (
              <Card key={brand}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">{brand}</CardTitle>
                  <Package className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{formatNumber(count)}</div>
                </CardContent>
              </Card>
            ))}
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center gap-2">
            <MapPin className="h-4 w-4 text-muted-foreground" />
            <Select value={regionFilter} onValueChange={handleRegionChange}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="All Regions" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Regions</SelectItem>
                {filterOptions.regions.map((r) => (
                  <SelectItem key={r} value={r}>
                    {r}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex items-center gap-2">
            <Select value={provinceFilter} onValueChange={handleProvinceChange}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="All Provinces" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Provinces</SelectItem>
                {filterOptions.provinces.map((p) => (
                  <SelectItem key={p} value={p}>
                    {p}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {(regionFilter !== 'all' || provinceFilter !== 'all') && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setRegionFilter('all');
                setProvinceFilter('all');
                applyFilters('all', 'all');
              }}
            >
              Clear Filters
            </Button>
          )}
        </div>

        {/* Inventory Breakdown Table */}
        <Card>
          <CardHeader>
            <CardTitle>Eligible Leads by Brand, Region & Age</CardTitle>
          </CardHeader>
          <CardContent>
            {filteredBreakdown.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-12 text-center">
                <Calendar className="h-12 w-12 text-muted-foreground mb-3" />
                <p className="text-muted-foreground">
                  No eligible leads found for the current filters.
                </p>
                <p className="text-xs text-muted-foreground mt-1">
                  Ensure leads have source_waybill_id and address_mapping_id set, and that
                  telesales_brand_configs are configured.
                </p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left">
                      <th className="pb-3 pr-4 font-medium">Brand</th>
                      <th className="pb-3 pr-4 font-medium">Region</th>
                      <th className="pb-3 pr-4 text-right font-medium">0–7 Days</th>
                      <th className="pb-3 pr-4 text-right font-medium">8–30 Days</th>
                      <th className="pb-3 pr-4 text-right font-medium">
                        31–{maxWaybillAgeDays} Days
                      </th>
                      <th className="pb-3 text-right font-medium">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredBreakdown.map((row, i) => (
                      <tr
                        key={`${row.brand}-${row.region}-${i}`}
                        className="border-b last:border-0"
                      >
                        <td className="py-3 pr-4 font-medium">{row.brand}</td>
                        <td className="py-3 pr-4">
                          <Badge variant="secondary">{row.region}</Badge>
                        </td>
                        <td className="py-3 pr-4 text-right tabular-nums">
                          {formatNumber(row.age_0_7)}
                        </td>
                        <td className="py-3 pr-4 text-right tabular-nums">
                          {formatNumber(row.age_8_30)}
                        </td>
                        <td className="py-3 pr-4 text-right tabular-nums">
                          {formatNumber(row.age_31_60)}
                        </td>
                        <td className="py-3 text-right tabular-nums font-semibold">
                          {formatNumber(row.total)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 font-semibold">
                      <td className="pt-3 pr-4" colSpan={5}>
                        Grand Total
                      </td>
                      <td className="pt-3 text-right tabular-nums">{formatNumber(grandTotal)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Region Summary */}
        <Card>
          <CardHeader>
            <CardTitle>Eligible Leads by Region</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid gap-3 md:grid-cols-5">
              {filterOptions.regions.map((region) => (
                <div
                  key={region}
                  className="flex items-center justify-between rounded-lg border p-3"
                >
                  <span className="text-sm font-medium">{region}</span>
                  <span className="text-lg font-bold tabular-nums">
                    {formatNumber(summary.by_region[region] ?? 0)}
                  </span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>
    </TelesalesLayout>
  );
}
