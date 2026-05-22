import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Search } from 'lucide-react';
import type { Waybill } from '@/types';
import axios from 'axios';

interface Props {
  prefill_waybill?: Waybill | null;
  prefill_type?: string | null;
}

export default function ClaimsCreate({ prefill_waybill, prefill_type }: Props) {
  const [waybillSearch, setWaybillSearch] = useState(prefill_waybill?.waybill_number ?? '');
  const [foundWaybill, setFoundWaybill] = useState<Waybill | null>(prefill_waybill ?? null);
  const [searching, setSearching] = useState(false);
  const [searchError, setSearchError] = useState('');

  const { data, setData, post, processing, errors } = useForm({
    waybill_id: prefill_waybill?.id?.toString() ?? '',
    type: prefill_type ?? '',
    description: '',
    claim_amount: prefill_waybill?.amount?.toString() ?? '',
  });

  async function searchWaybill() {
    if (!waybillSearch.trim()) return;
    setSearching(true);
    setSearchError('');
    setFoundWaybill(null);

    try {
      const res = await axios.get('/waybills/search', { params: { q: waybillSearch.trim() } });
      if (res.data.waybill) {
        setFoundWaybill(res.data.waybill);
        setData('waybill_id', String(res.data.waybill.id));
        if (!data.claim_amount) {
          setData('claim_amount', String(res.data.waybill.amount ?? ''));
        }
      } else {
        setSearchError('Waybill not found.');
      }
    } catch {
      setSearchError('Could not search waybills. Try again.');
    } finally {
      setSearching(false);
    }
  }

  function submit(e: React.FormEvent) {
    e.preventDefault();
    post('/waybills/claims');
  }

  return (
    <AppLayout>
      <Head title="File New Claim" />

      <div className="max-w-2xl space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center gap-3">
          <Link href="/waybills/claims">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold">File New Claim</h1>
            <p className="text-sm text-muted-foreground">Submit a claim against J&T Express</p>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-6">
          {/* Waybill lookup */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Waybill</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex gap-2">
                <Input
                  placeholder="Enter waybill number..."
                  value={waybillSearch}
                  onChange={(e) => setWaybillSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), searchWaybill())}
                  className="flex-1"
                />
                <Button type="button" variant="secondary" onClick={searchWaybill} disabled={searching}>
                  <Search className="mr-2 h-4 w-4" />
                  {searching ? 'Searching...' : 'Find'}
                </Button>
              </div>

              {searchError && <p className="text-sm text-red-600">{searchError}</p>}
              {errors.waybill_id && <p className="text-sm text-red-600">{errors.waybill_id}</p>}

              {foundWaybill && (
                <div className="rounded-lg border bg-muted/30 p-4 space-y-1">
                  <p className="font-mono text-sm font-medium">{foundWaybill.waybill_number}</p>
                  <p className="text-sm">{foundWaybill.receiver_name} — {foundWaybill.city}</p>
                  <p className="text-sm text-muted-foreground">
                    COD: ₱{Number(foundWaybill.amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })} · Status: {foundWaybill.status}
                  </p>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Claim details */}
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Claim Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-1">
                <Label htmlFor="type">Claim Type</Label>
                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                  <SelectTrigger id="type">
                    <SelectValue placeholder="Select type..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="LOST">Lost Parcel — parcel was lost in transit</SelectItem>
                    <SelectItem value="DAMAGED">Damaged Parcel — parcel arrived damaged</SelectItem>
                    <SelectItem value="BEYOND_SLA">Beyond SLA — not returned by next calendar day</SelectItem>
                  </SelectContent>
                </Select>
                {errors.type && <p className="text-sm text-red-600">{errors.type}</p>}
              </div>

              <div className="space-y-1">
                <Label htmlFor="claim_amount">Claim Amount (₱)</Label>
                <Input
                  id="claim_amount"
                  type="number"
                  step="0.01"
                  min="0"
                  value={data.claim_amount}
                  onChange={(e) => setData('claim_amount', e.target.value)}
                  placeholder="0.00"
                />
                {errors.claim_amount && <p className="text-sm text-red-600">{errors.claim_amount}</p>}
              </div>

              <div className="space-y-1">
                <Label htmlFor="description">Description <span className="text-muted-foreground">(optional)</span></Label>
                <Textarea
                  id="description"
                  value={data.description}
                  onChange={(e) => setData('description', e.target.value)}
                  placeholder="Describe the issue, timeline, and any relevant details..."
                  rows={4}
                />
                {errors.description && <p className="text-sm text-red-600">{errors.description}</p>}
              </div>
            </CardContent>
          </Card>

          <div className="flex gap-3">
            <Button type="submit" disabled={processing || !data.waybill_id || !data.type}>
              {processing ? 'Creating...' : 'Create Claim'}
            </Button>
            <Link href="/waybills/claims">
              <Button type="button" variant="outline">Cancel</Button>
            </Link>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
