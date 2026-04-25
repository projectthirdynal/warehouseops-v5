import { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Settings, RefreshCw, AlertCircle, CheckCircle2 } from 'lucide-react';

interface Mapping {
  mapping_key: string;
  qbo_account_id: string;
  qbo_account_name?: string;
}

interface QboAccount {
  Id: string;
  Name: string;
  AccountType?: string;
  AccountSubType?: string;
}

interface Props {
  keys: Record<string, string>;
  mappings: Record<string, Mapping>;
  qbo_active: boolean;
}

export default function MappingsPage({ keys, mappings, qbo_active }: Props) {
  const [accounts, setAccounts] = useState<QboAccount[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function loadAccounts() {
    setLoading(true);
    setError(null);
    try {
      const res = await fetch('/finance/quickbooks/accounts', { headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error('Failed to load accounts from QuickBooks.');
      const data = await res.json();
      if (data.error) throw new Error(data.error);
      setAccounts(Array.isArray(data) ? data : []);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { if (qbo_active) loadAccounts(); }, [qbo_active]);

  function save(key: string, accountId: string) {
    const account = accounts.find(a => a.Id === accountId);
    router.post('/finance/quickbooks/mappings', {
      mapping_key: key,
      qbo_account_id: accountId,
      qbo_account_name: account?.Name ?? null,
    }, { preserveScroll: true });
  }

  return (
    <AppLayout>
      <Head title="QBO Account Mappings" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">QuickBooks Account Mappings</h1>
            <p className="text-sm text-muted-foreground">Map internal financial events to QuickBooks ledger accounts.</p>
          </div>
          {qbo_active && (
            <Button variant="outline" onClick={loadAccounts} disabled={loading}>
              <RefreshCw className={`mr-2 h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Reload Accounts
            </Button>
          )}
        </div>

        {!qbo_active && (
          <div className="flex items-center gap-3 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3">
            <AlertCircle className="h-5 w-5 text-yellow-600" />
            <p className="text-sm text-yellow-900">QuickBooks is not connected. Connect first on the QuickBooks dashboard.</p>
          </div>
        )}
        {error && (
          <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</div>
        )}

        <Card>
          <CardHeader><CardTitle className="text-base flex items-center gap-2"><Settings className="h-4 w-4" />Required Mappings</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            {Object.entries(keys).map(([key, label]) => {
              const current = mappings[key];
              return (
                <div key={key} className="grid grid-cols-12 items-center gap-3 rounded-md border p-3">
                  <div className="col-span-4">
                    <p className="font-medium">{label}</p>
                    <p className="font-mono text-xs text-muted-foreground">{key}</p>
                  </div>
                  <div className="col-span-7">
                    <Select value={current?.qbo_account_id ?? ''} onValueChange={(v) => save(key, v)}>
                      <SelectTrigger>
                        <SelectValue placeholder={qbo_active ? 'Select QBO account...' : 'Connect QBO first'} />
                      </SelectTrigger>
                      <SelectContent>
                        {accounts.map(a => (
                          <SelectItem key={a.Id} value={a.Id}>
                            {a.Name} <span className="ml-2 text-xs text-muted-foreground">{a.AccountType}</span>
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="col-span-1 text-right">
                    {current && <CheckCircle2 className="ml-auto h-4 w-4 text-green-500" />}
                  </div>
                </div>
              );
            })}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
