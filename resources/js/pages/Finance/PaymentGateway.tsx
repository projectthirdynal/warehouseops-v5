import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CreditCard, CheckCircle, XCircle, Link2, Plus, Settings } from 'lucide-react';
import { formatCurrency, formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Transaction {
  id: number;
  reference_number: string;
  gateway: string;
  status: 'PENDING' | 'VERIFIED' | 'RECONCILED' | 'FAILED' | 'REFUNDED';
  transaction_type: string;
  amount: number;
  currency: string;
  invoice_id: number | null;
  order_id: number | null;
  cod_settlement_id: number | null;
  sender_name: string | null;
  sender_account: string | null;
  description: string | null;
  transaction_date: string | null;
  verified_at: string | null;
  reconciled_at: string | null;
  reconciliation_ref: string | null;
  invoice?: { ref: string; client_name: string; amount_due: number } | null;
  verifiedBy?: { name: string } | null;
  reconciledBy?: { name: string } | null;
  created_at: string;
}

interface GatewayStats {
  total_received: number;
  pending_count: number;
  verified_count: number;
  reconciled_count: number;
  by_gateway: Record<string, { count: number; total: number }>;
}

interface GatewaySettings {
  [key: string]: string | number | boolean;
  gcash_enabled: boolean;
  gcash_number: string;
  bank_transfer_enabled: boolean;
  bank_name: string;
  bank_account_name: string;
  bank_account_number: string;
  maya_enabled: boolean;
  maya_number: string;
  card_enabled: boolean;
  auto_verify: boolean;
  auto_reconcile: boolean;
}

interface Props {
  transactions: PaginatedResponse<Transaction>;
  stats: GatewayStats;
  settings: GatewaySettings;
  filters: { status?: string; gateway?: string };
}

const statusCfg: Record<string, { label: string; color: string }> = {
  PENDING: { label: 'Pending', color: 'bg-warning/10 text-warning' },
  VERIFIED: { label: 'Verified', color: 'bg-info/10 text-info' },
  RECONCILED: { label: 'Reconciled', color: 'bg-success/10 text-success' },
  FAILED: { label: 'Failed', color: 'bg-destructive/10 text-destructive' },
  REFUNDED: { label: 'Refunded', color: 'bg-secondary/10 text-secondary' },
};

const gatewayLabel: Record<string, string> = {
  GCASH: 'GCash',
  BANK_TRANSFER: 'Bank Transfer',
  MAYA: 'Maya',
  CARD: 'Card',
};

export default function PaymentGateway({ transactions, stats, settings, filters }: Props) {
  const [showForm, setShowForm] = useState(false);
  const [showSettings, setShowSettings] = useState(false);
  const [reconcileId, setReconcileId] = useState<number | null>(null);
  const [reconcileInvoiceId, setReconcileInvoiceId] = useState('');
  const [form, setForm] = useState({
    gateway: 'GCASH',
    amount: '',
    sender_name: '',
    sender_account: '',
    sender_phone: '',
    description: '',
    reference_number: '',
  });
  const [settingsForm, setSettingsForm] = useState(settings);

  const handleStore = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/finance/payment-gateway',
      {
        ...form,
        amount: parseFloat(form.amount) || 0,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setShowForm(false);
          setForm({
            gateway: 'GCASH',
            amount: '',
            sender_name: '',
            sender_account: '',
            sender_phone: '',
            description: '',
            reference_number: '',
          });
        },
      }
    );
  };

  const handleVerify = (id: number) => {
    router.post(`/finance/payment-gateway/${id}/verify`, {}, { preserveScroll: true });
  };

  const handleFail = (id: number) => {
    const reason = prompt('Reason for marking as failed?');
    if (reason) {
      router.post(`/finance/payment-gateway/${id}/fail`, { reason }, { preserveScroll: true });
    }
  };

  const handleReconcile = (id: number) => {
    if (reconcileInvoiceId) {
      router.post(
        `/finance/payment-gateway/${id}/reconcile`,
        { invoice_id: parseInt(reconcileInvoiceId) },
        {
          preserveScroll: true,
          onSuccess: () => {
            setReconcileId(null);
            setReconcileInvoiceId('');
          },
        }
      );
    } else {
      router.post(`/finance/payment-gateway/${id}/reconcile`, {}, { preserveScroll: true });
    }
  };

  const handleSaveSettings = (e: React.FormEvent) => {
    e.preventDefault();
    router.patch('/finance/payment-gateway/settings', settingsForm, {
      preserveScroll: true,
      onSuccess: () => setShowSettings(false),
    });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display">Payment Gateway</h1>
            <p className="text-sm text-muted-foreground">
              GCash, bank transfer, Maya — with auto-reconciliation
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={() => setShowSettings(!showSettings)}>
              <Settings className="mr-1.5 h-4 w-4" />
              {showSettings ? 'Close' : 'Settings'}
            </Button>
            <Button onClick={() => setShowForm(!showForm)}>
              <Plus className="mr-1.5 h-4 w-4" />
              {showForm ? 'Cancel' : 'Record Payment'}
            </Button>
          </div>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-success">
                {formatCurrency(stats.total_received)}
              </p>
              <p className="text-xs text-muted-foreground">Total Received</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-warning">{stats.pending_count}</p>
              <p className="text-xs text-muted-foreground">Pending</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-info">{stats.verified_count}</p>
              <p className="text-xs text-muted-foreground">Verified</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold">{stats.reconciled_count}</p>
              <p className="text-xs text-muted-foreground">Reconciled</p>
            </CardContent>
          </Card>
        </div>

        {/* Gateway breakdown */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {Object.entries(stats.by_gateway).map(([gw, data]) => (
            <Card key={gw}>
              <CardContent className="p-3 text-center">
                <p className="text-sm font-medium">{gatewayLabel[gw] || gw}</p>
                <p className="text-lg font-bold">{formatCurrency(data.total)}</p>
                <p className="text-xs text-muted-foreground">{data.count} transactions</p>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Settings panel */}
        {showSettings && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Gateway Settings</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSaveSettings} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2 border rounded-lg p-3">
                    <label className="text-sm font-medium flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={settingsForm.gcash_enabled}
                        onChange={(e) =>
                          setSettingsForm((p) => ({ ...p, gcash_enabled: e.target.checked }))
                        }
                      />
                      GCash Enabled
                    </label>
                    <input
                      type="text"
                      placeholder="GCash number"
                      value={settingsForm.gcash_number}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, gcash_number: e.target.value }))
                      }
                      className="w-full border rounded px-3 py-2 text-sm"
                    />
                  </div>
                  <div className="space-y-2 border rounded-lg p-3">
                    <label className="text-sm font-medium flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={settingsForm.bank_transfer_enabled}
                        onChange={(e) =>
                          setSettingsForm((p) => ({
                            ...p,
                            bank_transfer_enabled: e.target.checked,
                          }))
                        }
                      />
                      Bank Transfer Enabled
                    </label>
                    <input
                      type="text"
                      placeholder="Bank name"
                      value={settingsForm.bank_name}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, bank_name: e.target.value }))
                      }
                      className="w-full border rounded px-3 py-2 text-sm"
                    />
                    <input
                      type="text"
                      placeholder="Account name"
                      value={settingsForm.bank_account_name}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, bank_account_name: e.target.value }))
                      }
                      className="w-full border rounded px-3 py-2 text-sm"
                    />
                    <input
                      type="text"
                      placeholder="Account number"
                      value={settingsForm.bank_account_number}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, bank_account_number: e.target.value }))
                      }
                      className="w-full border rounded px-3 py-2 text-sm"
                    />
                  </div>
                  <div className="space-y-2 border rounded-lg p-3">
                    <label className="text-sm font-medium flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={settingsForm.maya_enabled}
                        onChange={(e) =>
                          setSettingsForm((p) => ({ ...p, maya_enabled: e.target.checked }))
                        }
                      />
                      Maya Enabled
                    </label>
                    <input
                      type="text"
                      placeholder="Maya number"
                      value={settingsForm.maya_number}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, maya_number: e.target.value }))
                      }
                      className="w-full border rounded px-3 py-2 text-sm"
                    />
                  </div>
                  <div className="space-y-2 border rounded-lg p-3">
                    <label className="text-sm font-medium flex items-center gap-2">
                      <input
                        type="checkbox"
                        checked={settingsForm.card_enabled}
                        onChange={(e) =>
                          setSettingsForm((p) => ({ ...p, card_enabled: e.target.checked }))
                        }
                      />
                      Card Enabled
                    </label>
                  </div>
                </div>
                <div className="flex items-center gap-4">
                  <label className="text-sm font-medium flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={settingsForm.auto_verify}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, auto_verify: e.target.checked }))
                      }
                    />
                    Auto-verify transactions
                  </label>
                  <label className="text-sm font-medium flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={settingsForm.auto_reconcile}
                      onChange={(e) =>
                        setSettingsForm((p) => ({ ...p, auto_reconcile: e.target.checked }))
                      }
                    />
                    Auto-reconcile
                  </label>
                </div>
                <Button type="submit" className="w-full">
                  Save Settings
                </Button>
              </form>
            </CardContent>
          </Card>
        )}

        {/* New transaction form */}
        {showForm && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Record Payment Transaction</CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleStore} className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium mb-1">Gateway</label>
                  <select
                    value={form.gateway}
                    onChange={(e) => setForm((p) => ({ ...p, gateway: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                  >
                    <option value="GCASH">GCash</option>
                    <option value="BANK_TRANSFER">Bank Transfer</option>
                    <option value="MAYA">Maya</option>
                    <option value="CARD">Card</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Amount (PHP)</label>
                  <input
                    type="number"
                    step="0.01"
                    value={form.amount}
                    onChange={(e) => setForm((p) => ({ ...p, amount: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                    required
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Reference Number</label>
                  <input
                    type="text"
                    value={form.reference_number}
                    onChange={(e) => setForm((p) => ({ ...p, reference_number: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                    placeholder="Auto-generated if empty"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Sender Name</label>
                  <input
                    type="text"
                    value={form.sender_name}
                    onChange={(e) => setForm((p) => ({ ...p, sender_name: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Sender Account</label>
                  <input
                    type="text"
                    value={form.sender_account}
                    onChange={(e) => setForm((p) => ({ ...p, sender_account: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium mb-1">Sender Phone</label>
                  <input
                    type="text"
                    value={form.sender_phone}
                    onChange={(e) => setForm((p) => ({ ...p, sender_phone: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                  />
                </div>
                <div className="col-span-2">
                  <label className="block text-sm font-medium mb-1">Description</label>
                  <input
                    type="text"
                    value={form.description}
                    onChange={(e) => setForm((p) => ({ ...p, description: e.target.value }))}
                    className="w-full border rounded-lg px-3 py-2 text-sm"
                  />
                </div>
                <div className="col-span-2">
                  <Button type="submit" className="w-full">
                    Record Transaction
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Filters */}
        <div className="flex items-center gap-3">
          <select
            value={filters.status || ''}
            onChange={(e) =>
              router.get(
                '/finance/payment-gateway',
                { ...filters, status: e.target.value || undefined },
                { preserveState: true }
              )
            }
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Status</option>
            <option value="PENDING">Pending</option>
            <option value="VERIFIED">Verified</option>
            <option value="RECONCILED">Reconciled</option>
            <option value="FAILED">Failed</option>
          </select>
          <select
            value={filters.gateway || ''}
            onChange={(e) =>
              router.get(
                '/finance/payment-gateway',
                { ...filters, gateway: e.target.value || undefined },
                { preserveState: true }
              )
            }
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Gateways</option>
            <option value="GCASH">GCash</option>
            <option value="BANK_TRANSFER">Bank Transfer</option>
            <option value="MAYA">Maya</option>
            <option value="CARD">Card</option>
          </select>
        </div>

        {/* Transaction list */}
        <div className="space-y-2">
          {transactions.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <CreditCard className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p>No payment transactions recorded yet.</p>
              </CardContent>
            </Card>
          ) : (
            transactions.data.map((t) => {
              const cfg = statusCfg[t.status] ?? statusCfg.PENDING;
              return (
                <Card key={t.id}>
                  <CardContent className="p-4">
                    <div className="flex items-center justify-between gap-4">
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="font-medium font-mono text-sm">
                            {t.reference_number}
                          </span>
                          <Badge variant="outline">{gatewayLabel[t.gateway] || t.gateway}</Badge>
                          <Badge className={`${cfg.color} text-[10px]`}>{cfg.label}</Badge>
                          {t.invoice && (
                            <span className="text-xs text-muted-foreground">→ {t.invoice.ref}</span>
                          )}
                          {t.reconciliation_ref && (
                            <span className="text-xs text-muted-foreground">
                              | {t.reconciliation_ref}
                            </span>
                          )}
                        </div>
                        <div className="text-xs text-muted-foreground mt-1">
                          {t.sender_name && `From: ${t.sender_name} | `}
                          {t.transaction_date
                            ? formatDate(t.transaction_date)
                            : formatDate(t.created_at)}
                          {t.verifiedBy && ` | Verified by ${t.verifiedBy.name}`}
                          {t.reconciledBy && ` | Reconciled by ${t.reconciledBy.name}`}
                        </div>
                      </div>
                      <div className="text-right shrink-0">
                        <p className="font-semibold">{formatCurrency(t.amount)}</p>
                      </div>
                      <div className="flex gap-1 shrink-0">
                        {t.status === 'PENDING' && (
                          <>
                            <Button size="sm" variant="outline" onClick={() => handleVerify(t.id)}>
                              <CheckCircle className="mr-1 h-3.5 w-3.5" />
                              Verify
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => handleFail(t.id)}>
                              <XCircle className="h-3.5 w-3.5" />
                            </Button>
                          </>
                        )}
                        {t.status === 'VERIFIED' && (
                          <>
                            {reconcileId === t.id ? (
                              <div className="flex items-center gap-1">
                                <input
                                  type="number"
                                  placeholder="Invoice ID"
                                  value={reconcileInvoiceId}
                                  onChange={(e) => setReconcileInvoiceId(e.target.value)}
                                  className="w-24 border rounded px-2 py-1 text-xs"
                                />
                                <Button size="sm" onClick={() => handleReconcile(t.id)}>
                                  <Link2 className="h-3.5 w-3.5" />
                                </Button>
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  onClick={() => setReconcileId(null)}
                                >
                                  Cancel
                                </Button>
                              </div>
                            ) : (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setReconcileId(t.id)}
                              >
                                <Link2 className="mr-1 h-3.5 w-3.5" />
                                Reconcile
                              </Button>
                            )}
                          </>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {transactions.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: transactions.last_page }, (_, i) => i + 1).map((page) => (
              <Button
                key={page}
                variant={page === transactions.current_page ? 'default' : 'outline'}
                size="sm"
                onClick={() => router.get('/finance/payment-gateway', { ...filters, page })}
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
