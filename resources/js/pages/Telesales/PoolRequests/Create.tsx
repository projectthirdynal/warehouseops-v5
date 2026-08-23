import { useState, useEffect, useCallback } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { Loader2, ArrowLeft } from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface FilterOptions {
  brands: Array<{ id: string; name: string }>;
  regions: string[];
  provinces: string[];
  sources: string[];
}

interface Props {
  filterOptions: FilterOptions;
}

export default function PoolRequestCreate({ filterOptions }: Props) {
  const [availableCount, setAvailableCount] = useState<number | null>(null);
  const [counting, setCounting] = useState(false);

  const { data, setData, post, processing, errors } = useForm({
    brand_name: '',
    product_name: '',
    business_region: '',
    province: '',
    city: '',
    lead_age_from: 0,
    lead_age_to: 60,
    requested_quantity: 100,
    distribution_method: 'equal',
    notes: '',
  });

  const fetchCount = useCallback(async () => {
    if (!data.brand_name) {
      setAvailableCount(null);
      return;
    }
    setCounting(true);
    try {
      const params = new URLSearchParams();
      if (data.brand_name) params.set('brand', data.brand_name);
      if (data.product_name) params.set('product', data.product_name);
      if (data.business_region) params.set('business_region', data.business_region);
      if (data.province) params.set('province', data.province);
      if (data.lead_age_from !== null) params.set('age_from', String(data.lead_age_from));
      if (data.lead_age_to !== null) params.set('age_to', String(data.lead_age_to));

      const res = await fetch(`/telesales/pool-requests/eligible/count?${params}`);
      const json = await res.json();
      setAvailableCount(json.count);
    } catch {
      setAvailableCount(null);
    } finally {
      setCounting(false);
    }
  }, [
    data.brand_name,
    data.product_name,
    data.business_region,
    data.province,
    data.lead_age_from,
    data.lead_age_to,
  ]);

  useEffect(() => {
    const timer = setTimeout(fetchCount, 400);
    return () => clearTimeout(timer);
  }, [fetchCount]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/telesales/pool-requests');
  };

  const canFulfill = availableCount !== null && availableCount >= data.requested_quantity;

  return (
    <TelesalesLayout>
      <Head title="Create Pool Request — Telesales" />
      <div className="space-y-6 p-6 max-w-3xl">
        <div className="flex items-center gap-4">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => router.visit('/telesales/pool-requests')}
          >
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
          </Button>
          <h1 className="text-2xl font-bold tracking-tight">Create Pool Request</h1>
        </div>

        <form onSubmit={handleSubmit}>
          <Card>
            <CardHeader>
              <CardTitle>Pool Criteria</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {/* Brand */}
              <div className="space-y-2">
                <Label htmlFor="brand">Brand *</Label>
                <Select value={data.brand_name} onValueChange={(v) => setData('brand_name', v)}>
                  <SelectTrigger id="brand">
                    <SelectValue placeholder="Select a brand" />
                  </SelectTrigger>
                  <SelectContent>
                    {filterOptions.brands.map((b) => (
                      <SelectItem key={b.id} value={b.id}>
                        {b.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {errors.brand_name && <p className="text-xs text-red-500">{errors.brand_name}</p>}
              </div>

              {/* Product */}
              <div className="space-y-2">
                <Label htmlFor="product">Product (optional)</Label>
                <Input
                  id="product"
                  value={data.product_name}
                  onChange={(e) => setData('product_name', e.target.value)}
                  placeholder="e.g. Black Garlic Coffee 30 capsules"
                />
              </div>

              {/* Region + Province */}
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Business Region</Label>
                  <Select
                    value={data.business_region}
                    onValueChange={(v) => setData('business_region', v === 'all' ? '' : v)}
                  >
                    <SelectTrigger>
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
                <div className="space-y-2">
                  <Label>Province</Label>
                  <Select
                    value={data.province}
                    onValueChange={(v) => setData('province', v === 'all' ? '' : v)}
                  >
                    <SelectTrigger>
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
              </div>

              {/* Age Range */}
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="age_from">Lead Age From (days)</Label>
                  <Input
                    id="age_from"
                    type="number"
                    min={0}
                    max={365}
                    value={data.lead_age_from}
                    onChange={(e) => setData('lead_age_from', parseInt(e.target.value) || 0)}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="age_to">Lead Age To (days)</Label>
                  <Input
                    id="age_to"
                    type="number"
                    min={1}
                    max={365}
                    value={data.lead_age_to}
                    onChange={(e) => setData('lead_age_to', parseInt(e.target.value) || 60)}
                  />
                </div>
              </div>

              {/* Available count */}
              <div className="rounded-lg border bg-muted/30 p-4">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium">Available Leads (live)</span>
                  {counting ? (
                    <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                  ) : (
                    <span
                      className={`text-2xl font-bold ${availableCount === null ? 'text-muted-foreground' : canFulfill ? 'text-green-600' : 'text-orange-600'}`}
                    >
                      {availableCount !== null ? availableCount.toLocaleString() : '—'}
                    </span>
                  )}
                </div>
                {availableCount !== null && !canFulfill && data.requested_quantity > 0 && (
                  <p className="text-xs text-orange-600 mt-1">
                    Only {availableCount.toLocaleString()} leads available — less than requested{' '}
                    {data.requested_quantity.toLocaleString()}.
                  </p>
                )}
              </div>

              {/* Requested Quantity + Distribution Method */}
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="qty">Requested Quantity *</Label>
                  <Input
                    id="qty"
                    type="number"
                    min={1}
                    max={50000}
                    value={data.requested_quantity}
                    onChange={(e) => setData('requested_quantity', parseInt(e.target.value) || 1)}
                  />
                  {errors.requested_quantity && (
                    <p className="text-xs text-red-500">{errors.requested_quantity}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label>Distribution Method</Label>
                  <Select
                    value={data.distribution_method}
                    onValueChange={(v) => setData('distribution_method', v)}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="equal">Equal</SelectItem>
                      <SelectItem value="manual_quantity">Manual Quantity</SelectItem>
                      <SelectItem value="round_robin">Round Robin</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {/* Notes */}
              <div className="space-y-2">
                <Label htmlFor="notes">Notes (optional)</Label>
                <Textarea
                  id="notes"
                  value={data.notes}
                  onChange={(e) => setData('notes', e.target.value)}
                  placeholder="Additional context for the approver..."
                  rows={3}
                />
              </div>

              <div className="flex justify-end gap-3 pt-2">
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => router.visit('/telesales/pool-requests')}
                >
                  Cancel
                </Button>
                <Button type="submit" disabled={processing || !data.brand_name}>
                  {processing ? 'Submitting...' : 'Submit for Approval'}
                </Button>
              </div>
            </CardContent>
          </Card>
        </form>
      </div>
    </TelesalesLayout>
  );
}
