import { useState, useEffect, useCallback } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from '@/components/ui/dialog';
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
import { ArrowLeft, Coins, Plus, RefreshCw, Trash2, TrendingUp, Calculator } from 'lucide-react';
import { formatDate } from '@/lib/utils';

interface Currency {
  code: string;
  name: string;
  symbol: string;
  decimal_places: number;
  is_active: boolean;
}

interface RateBoardEntry {
  from: string;
  to: string;
  rate: number;
  rate_date: string;
  source: string;
}

interface ExchangeRateRow {
  id: number;
  from_currency: string;
  to_currency: string;
  rate: number;
  rate_date: string;
  source: string;
  from_currency_rel?: { code: string; name: string; symbol: string };
  to_currency_rel?: { code: string; name: string; symbol: string };
}

interface Props {
  currencies: Currency[];
  rateBoard: RateBoardEntry[];
  rates: ExchangeRateRow[];
  ratesMeta: { current_page: number; last_page: number; per_page: number; total: number };
}

export default function MultiCurrency({ currencies, rateBoard, rates, ratesMeta }: Props) {
  const [rateDialogOpen, setRateDialogOpen] = useState(false);
  const [currencyDialogOpen, setCurrencyDialogOpen] = useState(false);
  const [convertDialogOpen, setConvertDialogOpen] = useState(false);
  const [rateList, setRateList] = useState<ExchangeRateRow[]>(rates);
  const [currentPage, setCurrentPage] = useState(ratesMeta.current_page);
  const [lastPage, setLastPage] = useState(ratesMeta.last_page);
  const [total, setTotal] = useState(ratesMeta.total);
  const [loadingRates, setLoadingRates] = useState(false);

  // Rate form state
  const [rateForm, setRateForm] = useState({
    from_currency: 'USD',
    to_currency: 'PHP',
    rate: '',
    rate_date: new Date().toISOString().split('T')[0],
    source: 'manual',
  });
  const [savingRate, setSavingRate] = useState(false);

  // Currency form state
  const [currencyForm, setCurrencyForm] = useState({
    code: '',
    name: '',
    symbol: '',
    decimal_places: '2',
    is_active: true,
  });
  const [savingCurrency, setSavingCurrency] = useState(false);

  // Convert form state
  const [convertForm, setConvertForm] = useState({
    amount: '',
    from: 'USD',
    to: 'PHP',
  });
  const [convertResult, setConvertResult] = useState<{
    original_amount: number;
    from_currency: string;
    to_currency: string;
    exchange_rate: number;
    converted_amount: number;
  } | null>(null);
  const [converting, setConverting] = useState(false);

  const activeCurrencies = currencies.filter((c) => c.is_active);

  const fetchRates = useCallback(async (page = 1) => {
    setLoadingRates(true);
    try {
      const res = await axios.get('/finance/multi-currency/api/exchange-rates', {
        params: { per_page: 25, page },
      });
      setRateList(res.data.data);
      setCurrentPage(res.data.meta.current_page);
      setLastPage(res.data.meta.last_page);
      setTotal(res.data.meta.total);
    } catch {
      toast.error('Failed to load exchange rates');
    } finally {
      setLoadingRates(false);
    }
  }, []);

  useEffect(() => {
    setRateList(rates);
  }, [rates]);

  const handleSaveRate = async () => {
    if (!rateForm.rate || parseFloat(rateForm.rate) <= 0) {
      toast.error('Rate must be a positive number');
      return;
    }
    setSavingRate(true);
    try {
      await axios.post('/finance/multi-currency/api/exchange-rates', rateForm);
      toast.success('Exchange rate saved');
      setRateDialogOpen(false);
      setRateForm({
        from_currency: 'USD',
        to_currency: 'PHP',
        rate: '',
        rate_date: new Date().toISOString().split('T')[0],
        source: 'manual',
      });
      fetchRates(currentPage);
    } catch {
      toast.error('Failed to save exchange rate');
    } finally {
      setSavingRate(false);
    }
  };

  const handleDeleteRate = async (id: number) => {
    if (!confirm('Delete this exchange rate?')) return;
    try {
      await axios.delete(`/finance/multi-currency/api/exchange-rates/${id}`);
      toast.success('Exchange rate deleted');
      fetchRates(currentPage);
    } catch {
      toast.error('Failed to delete rate');
    }
  };

  const handleSaveCurrency = async () => {
    if (!currencyForm.code || currencyForm.code.length !== 3) {
      toast.error('Currency code must be exactly 3 characters');
      return;
    }
    if (!currencyForm.name || !currencyForm.symbol) {
      toast.error('Name and symbol are required');
      return;
    }
    setSavingCurrency(true);
    try {
      await axios.post('/finance/multi-currency/api/currencies', {
        ...currencyForm,
        decimal_places: parseInt(currencyForm.decimal_places) || 2,
      });
      toast.success(`Currency ${currencyForm.code.toUpperCase()} saved`);
      setCurrencyDialogOpen(false);
      setCurrencyForm({ code: '', name: '', symbol: '', decimal_places: '2', is_active: true });
      router.reload({ only: ['currencies', 'rateBoard'] });
    } catch {
      toast.error('Failed to save currency');
    } finally {
      setSavingCurrency(false);
    }
  };

  const handleToggleCurrency = async (code: string) => {
    try {
      await axios.patch(`/finance/multi-currency/api/currencies/${code}/toggle`);
      toast.success(`Currency ${code} toggled`);
      router.reload({ only: ['currencies', 'rateBoard'] });
    } catch {
      toast.error('Failed to toggle currency');
    }
  };

  const handleConvert = async () => {
    if (!convertForm.amount || parseFloat(convertForm.amount) <= 0) {
      toast.error('Amount must be a positive number');
      return;
    }
    setConverting(true);
    try {
      const res = await axios.post('/finance/multi-currency/api/convert', convertForm);
      setConvertResult(res.data);
    } catch {
      toast.error('Conversion failed');
    } finally {
      setConverting(false);
    }
  };

  return (
    <AppLayout>
      <Head title="Multi-Currency" />
      <div className="container mx-auto p-6 space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link href="/finance">
              <Button variant="ghost" size="icon">
                <ArrowLeft className="h-5 w-5" />
              </Button>
            </Link>
            <div>
              <h1 className="text-2xl font-bold">Multi-Currency</h1>
              <p className="text-sm text-muted-foreground">
                Exchange rate management and currency conversion for international suppliers
              </p>
            </div>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => setConvertDialogOpen(true)}>
              <Calculator className="h-4 w-4 mr-2" />
              Convert
            </Button>
            <Button variant="outline" onClick={() => setCurrencyDialogOpen(true)}>
              <Coins className="h-4 w-4 mr-2" />
              Add Currency
            </Button>
            <Button onClick={() => setRateDialogOpen(true)}>
              <Plus className="h-4 w-4 mr-2" />
              Add Rate
            </Button>
          </div>
        </div>

        {/* Currency List */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Coins className="h-5 w-5" />
              Currencies ({currencies.length})
            </CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Code</TableHead>
                  <TableHead>Name</TableHead>
                  <TableHead>Symbol</TableHead>
                  <TableHead>Decimals</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {currencies.map((c) => (
                  <TableRow key={c.code}>
                    <TableCell className="font-mono font-semibold">{c.code}</TableCell>
                    <TableCell>{c.name}</TableCell>
                    <TableCell className="text-lg">{c.symbol}</TableCell>
                    <TableCell>{c.decimal_places}</TableCell>
                    <TableCell>
                      <Badge variant={c.is_active ? 'default' : 'secondary'}>
                        {c.is_active ? 'Active' : 'Inactive'}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleToggleCurrency(c.code)}
                      >
                        {c.is_active ? 'Deactivate' : 'Activate'}
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Rate Board — latest rates for all active pairs */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <TrendingUp className="h-5 w-5" />
              Rate Board ({rateBoard.length} pairs)
            </CardTitle>
          </CardHeader>
          <CardContent>
            {rateBoard.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4">
                No exchange rates configured. Click "Add Rate" to get started.
              </p>
            ) : (
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                {rateBoard.map((r) => (
                  <div
                    key={`${r.from}-${r.to}`}
                    className="flex items-center justify-between rounded-lg border p-3"
                  >
                    <div>
                      <div className="font-mono text-sm font-semibold">
                        {r.from} → {r.to}
                      </div>
                      <div className="text-xs text-muted-foreground">{formatDate(r.rate_date)}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-mono font-bold">{r.rate.toFixed(4)}</div>
                      <div className="text-xs text-muted-foreground">{r.source}</div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Exchange Rate History Table */}
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>Exchange Rate History ({total})</CardTitle>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => fetchRates(currentPage)}
                disabled={loadingRates}
              >
                <RefreshCw className={`h-4 w-4 ${loadingRates ? 'animate-spin' : ''}`} />
              </Button>
            </div>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>From</TableHead>
                  <TableHead>To</TableHead>
                  <TableHead>Rate</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Source</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rateList.map((r) => (
                  <TableRow key={r.id}>
                    <TableCell className="font-mono">{r.from_currency}</TableCell>
                    <TableCell className="font-mono">{r.to_currency}</TableCell>
                    <TableCell className="font-mono font-semibold">
                      {parseFloat(String(r.rate)).toFixed(6)}
                    </TableCell>
                    <TableCell>{formatDate(r.rate_date)}</TableCell>
                    <TableCell>
                      <Badge variant="outline">{r.source}</Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <Button variant="ghost" size="icon" onClick={() => handleDeleteRate(r.id)}>
                        <Trash2 className="h-4 w-4 text-red-500" />
                      </Button>
                    </TableCell>
                  </TableRow>
                ))}
                {rateList.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                      No exchange rates found.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>

            {/* Pagination */}
            {lastPage > 1 && (
              <div className="flex items-center justify-between mt-4">
                <p className="text-sm text-muted-foreground">
                  Page {currentPage} of {lastPage}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={currentPage <= 1}
                    onClick={() => fetchRates(currentPage - 1)}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={currentPage >= lastPage}
                    onClick={() => fetchRates(currentPage + 1)}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Add Rate Dialog */}
        <Dialog open={rateDialogOpen} onOpenChange={setRateDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add Exchange Rate</DialogTitle>
              <DialogDescription>
                Set the conversion rate between two currencies for a specific date.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>From Currency</Label>
                  <Select
                    value={rateForm.from_currency}
                    onValueChange={(v) => setRateForm({ ...rateForm, from_currency: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {activeCurrencies.map((c) => (
                        <SelectItem key={c.code} value={c.code}>
                          {c.code} — {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>To Currency</Label>
                  <Select
                    value={rateForm.to_currency}
                    onValueChange={(v) => setRateForm({ ...rateForm, to_currency: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {activeCurrencies.map((c) => (
                        <SelectItem key={c.code} value={c.code}>
                          {c.code} — {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="space-y-2">
                <Label>Rate</Label>
                <Input
                  type="number"
                  step="0.000001"
                  placeholder="e.g. 56.50"
                  value={rateForm.rate}
                  onChange={(e) => setRateForm({ ...rateForm, rate: e.target.value })}
                />
                <p className="text-xs text-muted-foreground">
                  1 {rateForm.from_currency} = ? {rateForm.to_currency}
                </p>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Date</Label>
                  <Input
                    type="date"
                    value={rateForm.rate_date}
                    onChange={(e) => setRateForm({ ...rateForm, rate_date: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label>Source</Label>
                  <Select
                    value={rateForm.source}
                    onValueChange={(v) => setRateForm({ ...rateForm, source: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="manual">Manual</SelectItem>
                      <SelectItem value="bsp">BSP (Bangko Sentral)</SelectItem>
                      <SelectItem value="cbn">CBN (Central Bank of Nigeria)</SelectItem>
                      <SelectItem value="ecb">ECB (European Central Bank)</SelectItem>
                      <SelectItem value="api">API Feed</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setRateDialogOpen(false)}>
                Cancel
              </Button>
              <Button onClick={handleSaveRate} disabled={savingRate}>
                {savingRate ? 'Saving...' : 'Save Rate'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Add Currency Dialog */}
        <Dialog open={currencyDialogOpen} onOpenChange={setCurrencyDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add Currency</DialogTitle>
              <DialogDescription>
                Register a new currency for use in purchase orders and supplier invoices.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div className="grid grid-cols-3 gap-4">
                <div className="space-y-2">
                  <Label>Code (3 chars)</Label>
                  <Input
                    maxLength={3}
                    placeholder="EUR"
                    value={currencyForm.code}
                    onChange={(e) =>
                      setCurrencyForm({ ...currencyForm, code: e.target.value.toUpperCase() })
                    }
                  />
                </div>
                <div className="col-span-2 space-y-2">
                  <Label>Name</Label>
                  <Input
                    placeholder="Euro"
                    value={currencyForm.name}
                    onChange={(e) => setCurrencyForm({ ...currencyForm, name: e.target.value })}
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>Symbol</Label>
                  <Input
                    placeholder="€"
                    value={currencyForm.symbol}
                    onChange={(e) => setCurrencyForm({ ...currencyForm, symbol: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label>Decimal Places</Label>
                  <Input
                    type="number"
                    min={0}
                    max={8}
                    value={currencyForm.decimal_places}
                    onChange={(e) =>
                      setCurrencyForm({ ...currencyForm, decimal_places: e.target.value })
                    }
                  />
                </div>
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setCurrencyDialogOpen(false)}>
                Cancel
              </Button>
              <Button onClick={handleSaveCurrency} disabled={savingCurrency}>
                {savingCurrency ? 'Saving...' : 'Save Currency'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Convert Dialog */}
        <Dialog open={convertDialogOpen} onOpenChange={setConvertDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Currency Converter</DialogTitle>
              <DialogDescription>
                Convert an amount using the latest available exchange rate.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div className="space-y-2">
                <Label>Amount</Label>
                <Input
                  type="number"
                  step="0.01"
                  placeholder="100.00"
                  value={convertForm.amount}
                  onChange={(e) => setConvertForm({ ...convertForm, amount: e.target.value })}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label>From</Label>
                  <Select
                    value={convertForm.from}
                    onValueChange={(v) => setConvertForm({ ...convertForm, from: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {activeCurrencies.map((c) => (
                        <SelectItem key={c.code} value={c.code}>
                          {c.code} — {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>To</Label>
                  <Select
                    value={convertForm.to}
                    onValueChange={(v) => setConvertForm({ ...convertForm, to: v })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {activeCurrencies.map((c) => (
                        <SelectItem key={c.code} value={c.code}>
                          {c.code} — {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
              {convertResult && (
                <div className="rounded-lg border bg-muted/50 p-4 space-y-2">
                  <div className="flex justify-between">
                    <span className="text-sm text-muted-foreground">Original</span>
                    <span className="font-mono font-semibold">
                      {convertResult.original_amount.toFixed(2)} {convertResult.from_currency}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-sm text-muted-foreground">Exchange Rate</span>
                    <span className="font-mono">{convertResult.exchange_rate.toFixed(6)}</span>
                  </div>
                  <div className="flex justify-between border-t pt-2">
                    <span className="text-sm text-muted-foreground">Converted</span>
                    <span className="font-mono font-bold text-lg">
                      {convertResult.converted_amount.toFixed(2)} {convertResult.to_currency}
                    </span>
                  </div>
                </div>
              )}
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setConvertDialogOpen(false)}>
                Close
              </Button>
              <Button onClick={handleConvert} disabled={converting}>
                {converting ? 'Converting...' : 'Convert'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </AppLayout>
  );
}
