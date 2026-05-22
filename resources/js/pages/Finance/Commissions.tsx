import { useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckCircle, DollarSign, CreditCard } from 'lucide-react';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface Commission {
  id: number;
  agent_id: number;
  order_id: number;
  sale_amount: number;
  commission_rate: number;
  commission_amount: number;
  status: 'PENDING' | 'APPROVED' | 'PAID' | 'CANCELLED';
  earned_at: string | null;
  paid_at: string | null;
  agent?: { id: number; name: string };
  order?: { order_number: string };
  product?: { name: string } | null;
}

interface Rule {
  id: number;
  product_id: number | null;
  rate_type: 'PERCENTAGE' | 'FIXED';
  rate_value: number;
  min_sale_amount: number | null;
  product?: { name: string } | null;
}

interface Props {
  commissions: PaginatedResponse<Commission>;
  stats: { pending: number; approved: number; paid: number };
  rules: Rule[];
  filters: { status?: string; agent_id?: string };
}

const statusCfg: Record<string, { label: string; color: string }> = {
  PENDING:   { label: 'Pending',   color: 'bg-yellow-100 text-yellow-800' },
  APPROVED:  { label: 'Approved',  color: 'bg-blue-100 text-blue-800' },
  PAID:      { label: 'Paid',      color: 'bg-green-100 text-green-800' },
  CANCELLED: { label: 'Cancelled', color: 'bg-gray-100 text-gray-600' },
};

export default function Commissions({ commissions, stats, rules, filters }: Props) {
  const [selected, setSelected] = useState<number[]>([]);
  const [ruleForm, setRuleForm] = useState({ rate_type: 'PERCENTAGE', rate_value: '', product_id: '', min_sale_amount: '' });
  const [showRuleForm, setShowRuleForm] = useState(false);

  const toggleSelect = (id: number) => {
    setSelected((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
  };

  const selectAllPending = () => {
    const pendingIds = commissions.data.filter((c) => c.status === 'PENDING').map((c) => c.id);
    setSelected(pendingIds);
  };

  const handleBulkApprove = () => {
    if (selected.length === 0) return;
    router.post('/finance/commissions/approve', { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
  };

  const handleBulkPay = () => {
    if (selected.length === 0) return;
    router.post('/finance/commissions/pay', { ids: selected }, { preserveScroll: true, onSuccess: () => setSelected([]) });
  };

  const handleCreateRule = (e: React.FormEvent) => {
    e.preventDefault();
    router.post('/finance/commissions/rules', {
      rate_type: ruleForm.rate_type,
      rate_value: parseFloat(ruleForm.rate_value) || 0,
      product_id: ruleForm.product_id || null,
      min_sale_amount: ruleForm.min_sale_amount ? parseFloat(ruleForm.min_sale_amount) : null,
    }, { preserveScroll: true, onSuccess: () => { setShowRuleForm(false); setRuleForm({ rate_type: 'PERCENTAGE', rate_value: '', product_id: '', min_sale_amount: '' }); } });
  };

  return (
    <AppLayout>
      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Commissions</h1>
            <p className="text-sm text-muted-foreground">Agent commission management</p>
          </div>
          <div className="flex gap-2">
            {selected.length > 0 && (
              <>
                <Button onClick={handleBulkApprove} size="sm" variant="outline">
                  <CheckCircle className="mr-1 h-3.5 w-3.5" />Approve ({selected.length})
                </Button>
                <Button onClick={handleBulkPay} size="sm">
                  <CreditCard className="mr-1 h-3.5 w-3.5" />Pay ({selected.length})
                </Button>
              </>
            )}
          </div>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-yellow-600">{formatCurrency(stats.pending)}</p>
              <p className="text-xs text-muted-foreground">Pending</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-blue-600">{formatCurrency(stats.approved)}</p>
              <p className="text-xs text-muted-foreground">Approved (unpaid)</p>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="p-4 text-center">
              <p className="text-xl font-bold text-green-600">{formatCurrency(stats.paid)}</p>
              <p className="text-xs text-muted-foreground">Total Paid</p>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <div className="flex items-center gap-3">
          <select
            value={filters.status || ''}
            onChange={(e) => router.get('/finance/commissions', { status: e.target.value || undefined }, { preserveState: true })}
            className="border rounded-lg px-3 py-2 text-sm"
          >
            <option value="">All Status</option>
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Approved</option>
            <option value="PAID">Paid</option>
            <option value="CANCELLED">Cancelled</option>
          </select>
          <Button variant="outline" size="sm" onClick={selectAllPending}>Select All Pending</Button>
        </div>

        {/* Commission list */}
        <div className="space-y-2">
          {commissions.data.length === 0 ? (
            <Card>
              <CardContent className="py-12 text-center text-muted-foreground">
                <DollarSign className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p>No commissions yet. They are created when orders are delivered.</p>
              </CardContent>
            </Card>
          ) : (
            commissions.data.map((c) => {
              const cfg = statusCfg[c.status] ?? statusCfg.PENDING;
              return (
                <Card key={c.id} className={selected.includes(c.id) ? 'ring-2 ring-primary' : ''}>
                  <CardContent className="p-4">
                    <div className="flex items-center gap-4">
                      <input
                        type="checkbox"
                        checked={selected.includes(c.id)}
                        onChange={() => toggleSelect(c.id)}
                        className="rounded"
                        disabled={c.status === 'PAID' || c.status === 'CANCELLED'}
                      />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="font-semibold">{c.agent?.name ?? 'Unknown'}</span>
                          <Badge className={`${cfg.color} text-[10px]`}>{cfg.label}</Badge>
                        </div>
                        <div className="text-xs text-muted-foreground mt-0.5">
                          Order {c.order?.order_number} | {c.product?.name ?? 'N/A'} | Rate: {c.commission_rate > 1 ? formatCurrency(c.commission_rate) : `${(c.commission_rate * 100).toFixed(1)}%`}
                        </div>
                      </div>
                      <div className="text-right shrink-0">
                        <p className="font-semibold text-green-600">{formatCurrency(c.commission_amount)}</p>
                        <p className="text-xs text-muted-foreground">of {formatCurrency(c.sale_amount)}</p>
                      </div>
                      <div className="text-xs text-muted-foreground shrink-0">
                        {c.earned_at ? formatDateTime(c.earned_at) : ''}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })
          )}
        </div>

        {/* Pagination */}
        {commissions.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: commissions.last_page }, (_, i) => i + 1).map((page) => (
              <Button key={page} variant={page === commissions.current_page ? 'default' : 'outline'} size="sm"
                onClick={() => router.get('/finance/commissions', { ...filters, page })}>
                {page}
              </Button>
            ))}
          </div>
        )}

        {/* Commission Rules */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base">Commission Rules</CardTitle>
            <Button variant="outline" size="sm" onClick={() => setShowRuleForm(!showRuleForm)}>
              {showRuleForm ? 'Cancel' : 'Add Rule'}
            </Button>
          </CardHeader>
          <CardContent>
            {showRuleForm && (
              <form onSubmit={handleCreateRule} className="grid grid-cols-4 gap-3 mb-4 p-3 bg-muted/30 rounded-lg">
                <select value={ruleForm.rate_type} onChange={(e) => setRuleForm((p) => ({ ...p, rate_type: e.target.value }))} className="border rounded px-2 py-1.5 text-sm">
                  <option value="PERCENTAGE">Percentage</option>
                  <option value="FIXED">Fixed Amount</option>
                </select>
                <input type="number" step="0.01" placeholder={ruleForm.rate_type === 'PERCENTAGE' ? 'Rate %' : 'Amount'} value={ruleForm.rate_value}
                  onChange={(e) => setRuleForm((p) => ({ ...p, rate_value: e.target.value }))} className="border rounded px-2 py-1.5 text-sm" required />
                <input type="text" placeholder="Product ID (blank=default)" value={ruleForm.product_id}
                  onChange={(e) => setRuleForm((p) => ({ ...p, product_id: e.target.value }))} className="border rounded px-2 py-1.5 text-sm" />
                <Button type="submit" size="sm">Create</Button>
              </form>
            )}
            {rules.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-4">No rules. Add a default rule to start calculating commissions.</p>
            ) : (
              <div className="space-y-2">
                {rules.map((r) => (
                  <div key={r.id} className="flex items-center justify-between p-3 rounded-lg border text-sm">
                    <div>
                      <span className="font-medium">{r.product ? r.product.name : 'Default (all products)'}</span>
                    </div>
                    <div className="flex items-center gap-4">
                      <Badge variant="outline">
                        {r.rate_type === 'PERCENTAGE' ? `${r.rate_value}%` : formatCurrency(r.rate_value)}
                      </Badge>
                      {r.min_sale_amount && (
                        <span className="text-muted-foreground">Min: {formatCurrency(r.min_sale_amount)}</span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
