import { useState, useCallback } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { ArrowLeft, Search, Trophy, Zap, Package } from 'lucide-react';

interface Provider {
  id: number;
  code: string;
  name: string;
}

interface RateResult {
  courier_code: string;
  courier_name: string;
  base_fee: number;
  per_kg_fee: number;
  cod_fee: number;
  total_fee: number;
  estimated_days: number | null;
  is_active: boolean;
  has_rate: boolean;
  zone: string | null;
}

interface ComparisonResult {
  zone: string | null;
  rates: RateResult[];
  cheapest: RateResult | null;
  fastest: RateResult | null;
}

interface Props {
  providers: Provider[];
}

export default function RateComparison({ providers }: Props) {
  const [form, setForm] = useState({
    province: '',
    city_municipality: '',
    barangay: '',
    address: '',
    weight: '',
    cod_amount: '',
    item_value: '',
  });
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<ComparisonResult | null>(null);

  const handleCompare = useCallback(
    (e: React.FormEvent) => {
      e.preventDefault();
      setLoading(true);
      axios
        .post('/couriers/compare-rates', {
          province: form.province || null,
          city_municipality: form.city_municipality || null,
          barangay: form.barangay || null,
          address: form.address || null,
          weight: form.weight || null,
          cod_amount: form.cod_amount || null,
          item_value: form.item_value || null,
        })
        .then(({ data }) => {
          setResult(data);
          if (data.rates.every((r: RateResult) => !r.has_rate)) {
            toast.warning('No rates found for this zone. Add rates in Rate Management.');
          }
        })
        .catch(() => toast.error('Failed to compare rates'))
        .finally(() => setLoading(false));
    },
    [form]
  );

  return (
    <AppLayout>
      <Head title="Rate Comparison" />

      <div className="space-y-4 p-6">
        <div className="flex items-center gap-3">
          <Link href="/waybills">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="h-4 w-4 mr-1" />
              Back
            </Button>
          </Link>
          <div className="flex-1">
            <h1 className="text-xl font-bold font-display">Rate Comparison</h1>
            <p className="text-sm text-muted-foreground">
              Compare shipping rates across all couriers before creating a waybill
            </p>
          </div>
          <Link href="/couriers/rate-management">
            <Button variant="outline" size="sm">
              Manage Rates
            </Button>
          </Link>
        </div>

        {/* Form */}
        <Card>
          <CardHeader>
            <CardTitle>Shipment Details</CardTitle>
            <CardDescription>
              Enter destination and package info to compare rates across {providers.length} courier
              {providers.length !== 1 ? 's' : ''}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleCompare} className="space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                  <label className="text-sm font-medium">Province</label>
                  <Input
                    value={form.province}
                    onChange={(e) => setForm({ ...form, province: e.target.value })}
                    placeholder="e.g. Metro Manila"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">City / Municipality</label>
                  <Input
                    value={form.city_municipality}
                    onChange={(e) => setForm({ ...form, city_municipality: e.target.value })}
                    placeholder="e.g. Quezon City"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">Barangay</label>
                  <Input
                    value={form.barangay}
                    onChange={(e) => setForm({ ...form, barangay: e.target.value })}
                    placeholder="e.g. Diliman"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">Weight (kg)</label>
                  <Input
                    type="number"
                    step="0.1"
                    min="0"
                    value={form.weight}
                    onChange={(e) => setForm({ ...form, weight: e.target.value })}
                    placeholder="e.g. 1.5"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">COD Amount (₱)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.cod_amount}
                    onChange={(e) => setForm({ ...form, cod_amount: e.target.value })}
                    placeholder="e.g. 500.00"
                  />
                </div>
                <div>
                  <label className="text-sm font-medium">Item Value (₱)</label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.item_value}
                    onChange={(e) => setForm({ ...form, item_value: e.target.value })}
                    placeholder="e.g. 1000.00"
                  />
                </div>
              </div>
              <Button type="submit" disabled={loading}>
                {loading ? (
                  <>
                    <Search className="h-4 w-4 mr-1 animate-spin" />
                    Comparing...
                  </>
                ) : (
                  <>
                    <Search className="h-4 w-4 mr-1" />
                    Compare Rates
                  </>
                )}
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Results */}
        {result && (
          <div className="space-y-4">
            {/* Summary cards */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              {result.cheapest && (
                <Card className="border-success/30">
                  <CardHeader className="pb-2">
                    <CardTitle className="flex items-center gap-2 text-sm">
                      <Trophy className="h-4 w-4 text-success" />
                      Cheapest Rate
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-lg font-bold font-display text-success">
                      ₱
                      {Number(result.cheapest.total_fee).toLocaleString('en-PH', {
                        minimumFractionDigits: 2,
                      })}
                    </p>
                    <p className="text-sm text-muted-foreground">{result.cheapest.courier_name}</p>
                  </CardContent>
                </Card>
              )}
              {result.fastest && (
                <Card className="border-info/30">
                  <CardHeader className="pb-2">
                    <CardTitle className="flex items-center gap-2 text-sm">
                      <Zap className="h-4 w-4 text-info" />
                      Fastest Delivery
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-lg font-bold font-display text-info">
                      {result.fastest.estimated_days}{' '}
                      {result.fastest.estimated_days === 1 ? 'day' : 'days'}
                    </p>
                    <p className="text-sm text-muted-foreground">{result.fastest.courier_name}</p>
                  </CardContent>
                </Card>
              )}
              {result.zone && (
                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="flex items-center gap-2 text-sm">
                      <Package className="h-4 w-4 text-muted-foreground" />
                      Resolved Zone
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-lg font-bold font-display">{result.zone}</p>
                    <p className="text-sm text-muted-foreground">Courier zone for this address</p>
                  </CardContent>
                </Card>
              )}
            </div>

            {/* Comparison table */}
            <Card>
              <CardHeader>
                <CardTitle>Rate Comparison</CardTitle>
                <CardDescription>
                  {result.rates.length} courier{result.rates.length !== 1 ? 's' : ''} compared
                </CardDescription>
              </CardHeader>
              <CardContent>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Courier</TableHead>
                      <TableHead>Zone</TableHead>
                      <TableHead className="text-right">Base Fee</TableHead>
                      <TableHead className="text-right">Per Kg Fee</TableHead>
                      <TableHead className="text-right">COD Fee</TableHead>
                      <TableHead className="text-right">Total Fee</TableHead>
                      <TableHead className="text-center">Est. Days</TableHead>
                      <TableHead className="text-center">Status</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {result.rates.map((rate) => (
                      <TableRow key={rate.courier_code}>
                        <TableCell className="font-medium">
                          <div className="flex items-center gap-2">
                            {rate.courier_name}
                            {result.cheapest?.courier_code === rate.courier_code && (
                              <span className="inline-flex items-center gap-0.5 rounded-full bg-success/10 px-1.5 py-0.5 text-xs font-medium text-success">
                                <Trophy className="h-3 w-3" />
                                Cheapest
                              </span>
                            )}
                            {result.fastest?.courier_code === rate.courier_code && (
                              <span className="inline-flex items-center gap-0.5 rounded-full bg-info/10 px-1.5 py-0.5 text-xs font-medium text-info">
                                <Zap className="h-3 w-3" />
                                Fastest
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="text-sm">{rate.zone ?? '—'}</TableCell>
                        <TableCell className="text-right text-sm">
                          {rate.has_rate
                            ? `₱${Number(rate.base_fee).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                            : '—'}
                        </TableCell>
                        <TableCell className="text-right text-sm">
                          {rate.has_rate
                            ? `₱${Number(rate.per_kg_fee).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                            : '—'}
                        </TableCell>
                        <TableCell className="text-right text-sm">
                          {rate.cod_fee > 0
                            ? `₱${Number(rate.cod_fee).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                            : '—'}
                        </TableCell>
                        <TableCell className="text-right font-bold">
                          {rate.has_rate ? (
                            <span
                              className={
                                result.cheapest?.courier_code === rate.courier_code
                                  ? 'text-success'
                                  : ''
                              }
                            >
                              ₱
                              {Number(rate.total_fee).toLocaleString('en-PH', {
                                minimumFractionDigits: 2,
                              })}
                            </span>
                          ) : (
                            <span className="text-muted-foreground">No rate</span>
                          )}
                        </TableCell>
                        <TableCell className="text-center text-sm">
                          {rate.estimated_days !== null ? `${rate.estimated_days}d` : '—'}
                        </TableCell>
                        <TableCell className="text-center">
                          {rate.has_rate ? (
                            <span className="inline-flex items-center rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success">
                              Available
                            </span>
                          ) : (
                            <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                              No Rate
                            </span>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
