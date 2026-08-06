import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AlertTriangle, CheckCircle2, Clock, Layers, Package, ShieldX } from 'lucide-react';

interface MatchSummary {
  id: number;
  po_id: number;
  po_number: string;
  supplier: string | null;
  po_status: string;
  supplier_invoice_id: number | null;
  supplier_invoice_ref: string | null;
  invoice_status: string | null;
  status: string;
  match_level: string;
  po_total: number;
  grn_total: number;
  invoice_total: number;
  variance_amount: number;
  mismatch_count: number;
  mismatches: Array<{
    type: string;
    severity: string;
    message: string;
  }> | null;
  matched_by: string | null;
  matched_at: string | null;
  updated_at: string | null;
}

interface Stats {
  total: number;
  matched: number;
  mismatch: number;
  blocked: number;
  pending: number;
  match_rate: number;
  total_variance: number;
}

interface EligiblePO {
  id: number;
  po_number: string;
  supplier: string | null;
  status: string;
  total_amount: number;
  items_count: number;
}

interface Props {
  matches: MatchSummary[];
  stats: Stats;
  eligible_pos: EligiblePO[];
  filters: { status?: string };
}

const STATUS_BADGE: Record<string, { color: string; icon: typeof CheckCircle2; label: string }> = {
  MATCHED: { color: 'text-success', icon: CheckCircle2, label: 'Matched' },
  MISMATCH: { color: 'text-warning', icon: AlertTriangle, label: 'Mismatch' },
  BLOCKED: { color: 'text-destructive', icon: ShieldX, label: 'Blocked' },
  PENDING: { color: 'text-muted-foreground', icon: Clock, label: 'Pending' },
};

export default function ThreeWayMatchDashboard({ matches, stats, eligible_pos, filters }: Props) {
  const [statusFilter, setStatusFilter] = useState(filters.status ?? '');
  const [selectedPoId, setSelectedPoId] = useState('');
  const [selectedInvoiceId, setSelectedInvoiceId] = useState('');

  function applyFilter() {
    const params: Record<string, string> = {};
    if (statusFilter) params.status = statusFilter;
    router.get('/finance/three-way-match', params, { preserveScroll: true });
  }

  function runMatch() {
    if (!selectedPoId) return;
    const data: Record<string, string> = { po_id: selectedPoId };
    if (selectedInvoiceId) data.supplier_invoice_id = selectedInvoiceId;
    router.post('/finance/three-way-match/run', data);
  }

  return (
    <>
      <Head title="Three-Way Match" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Three-Way Match</h1>
            <p className="text-sm text-muted-foreground">
              PO &rarr; Receiving &rarr; Supplier Invoice verification
            </p>
          </div>
          <Link href="/finance" className="text-sm text-muted-foreground hover:text-foreground">
            &larr; Finance
          </Link>
        </div>

        {/* Stats Cards */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
          <StatCard label="Total" value={stats.total} icon={Layers} color="text-foreground" />
          <StatCard
            label="Matched"
            value={stats.matched}
            icon={CheckCircle2}
            color="text-success"
          />
          <StatCard
            label="Mismatch"
            value={stats.mismatch}
            icon={AlertTriangle}
            color="text-warning"
          />
          <StatCard label="Blocked" value={stats.blocked} icon={ShieldX} color="text-destructive" />
          <StatCard
            label="Pending"
            value={stats.pending}
            icon={Clock}
            color="text-muted-foreground"
          />
          <StatCard
            label="Match Rate"
            value={`${stats.match_rate}%`}
            icon={CheckCircle2}
            color="text-info"
          />
          <StatCard
            label="Variance"
            value={`₱${stats.total_variance.toLocaleString()}`}
            icon={AlertTriangle}
            color={stats.total_variance !== 0 ? 'text-destructive' : 'text-muted-foreground'}
          />
        </div>

        {/* Run New Match */}
        <div className="rounded-lg border p-4">
          <h2 className="mb-3 text-lg font-semibold">Run New Match</h2>
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex-1 min-w-[200px]">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">
                Purchase Order
              </label>
              <select
                className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                value={selectedPoId}
                onChange={(e) => setSelectedPoId(e.target.value)}
              >
                <option value="">Select PO…</option>
                {eligible_pos.map((po) => (
                  <option key={po.id} value={po.id}>
                    {po.po_number} — {po.supplier ?? 'Unknown'} (₱{po.total_amount.toLocaleString()}
                    )
                  </option>
                ))}
              </select>
            </div>
            <div className="flex-1 min-w-[200px]">
              <label className="mb-1 block text-xs font-medium text-muted-foreground">
                Supplier Invoice (optional)
              </label>
              <input
                type="number"
                className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                placeholder="Invoice ID…"
                value={selectedInvoiceId}
                onChange={(e) => setSelectedInvoiceId(e.target.value)}
              />
            </div>
            <button
              className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
              disabled={!selectedPoId}
              onClick={runMatch}
            >
              Run Match
            </button>
          </div>
          {eligible_pos.length === 0 && (
            <p className="mt-2 text-xs text-muted-foreground">
              No eligible POs pending match (SENT, PARTIALLY_RECEIVED, or RECEIVED without existing
              match).
            </p>
          )}
        </div>

        {/* Filter Bar */}
        <div className="flex items-center gap-3">
          <label className="text-sm font-medium">Filter by status:</label>
          <select
            className="rounded-md border bg-background px-3 py-1.5 text-sm"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            <option value="">All</option>
            <option value="MATCHED">Matched</option>
            <option value="MISMATCH">Mismatch</option>
            <option value="BLOCKED">Blocked</option>
            <option value="PENDING">Pending</option>
          </select>
          <button className="rounded-md border px-3 py-1.5 text-sm" onClick={applyFilter}>
            Apply
          </button>
        </div>

        {/* Match List */}
        <div className="rounded-lg border">
          <div className="border-b p-4">
            <h2 className="text-lg font-semibold">Recent Matches</h2>
          </div>
          {matches.length === 0 ? (
            <div className="p-8 text-center text-muted-foreground">
              <Package className="mx-auto mb-2 h-8 w-8 opacity-50" />
              <p>No three-way matches found.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b bg-muted/50">
                  <tr>
                    <th className="px-4 py-2 text-left font-medium">PO Number</th>
                    <th className="px-4 py-2 text-left font-medium">Supplier</th>
                    <th className="px-4 py-2 text-left font-medium">Invoice</th>
                    <th className="px-4 py-2 text-right font-medium">PO Total</th>
                    <th className="px-4 py-2 text-right font-medium">GRN Total</th>
                    <th className="px-4 py-2 text-right font-medium">Invoice Total</th>
                    <th className="px-4 py-2 text-right font-medium">Variance</th>
                    <th className="px-4 py-2 text-center font-medium">Status</th>
                    <th className="px-4 py-2 text-center font-medium">Mismatches</th>
                    <th className="px-4 py-2 text-left font-medium">Matched</th>
                    <th className="px-4 py-2"></th>
                  </tr>
                </thead>
                <tbody>
                  {matches.map((m) => {
                    const badge = STATUS_BADGE[m.status] ?? STATUS_BADGE.PENDING;
                    const Icon = badge.icon;
                    return (
                      <tr key={m.id} className="border-b last:border-0 hover:bg-muted/30">
                        <td className="px-4 py-2 font-medium">{m.po_number}</td>
                        <td className="px-4 py-2">{m.supplier ?? '—'}</td>
                        <td className="px-4 py-2">{m.supplier_invoice_ref ?? '—'}</td>
                        <td className="px-4 py-2 text-right">₱{m.po_total.toLocaleString()}</td>
                        <td className="px-4 py-2 text-right">₱{m.grn_total.toLocaleString()}</td>
                        <td className="px-4 py-2 text-right">
                          {m.invoice_total > 0 ? `₱${m.invoice_total.toLocaleString()}` : '—'}
                        </td>
                        <td
                          className={`px-4 py-2 text-right font-medium ${m.variance_amount !== 0 ? 'text-warning' : ''}`}
                        >
                          {m.variance_amount !== 0 ? `₱${m.variance_amount.toLocaleString()}` : '—'}
                        </td>
                        <td className="px-4 py-2 text-center">
                          <span className={`inline-flex items-center gap-1 ${badge.color}`}>
                            <Icon className="h-3.5 w-3.5" />
                            {badge.label}
                          </span>
                        </td>
                        <td className="px-4 py-2 text-center">
                          {m.mismatch_count > 0 ? (
                            <span className="inline-flex items-center gap-1 text-warning">
                              <AlertTriangle className="h-3.5 w-3.5" />
                              {m.mismatch_count}
                            </span>
                          ) : (
                            <span className="text-success">0</span>
                          )}
                        </td>
                        <td className="px-4 py-2 text-xs text-muted-foreground">
                          {m.matched_by ?? '—'}
                          {m.matched_at && <div>{new Date(m.matched_at).toLocaleDateString()}</div>}
                        </td>
                        <td className="px-4 py-2">
                          <Link
                            href={`/finance/three-way-match/${m.id}`}
                            className="text-primary hover:underline"
                          >
                            View
                          </Link>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </>
  );
}

function StatCard({
  label,
  value,
  icon: Icon,
  color,
}: {
  label: string;
  value: string | number;
  icon: typeof Layers;
  color: string;
}) {
  return (
    <div className="rounded-lg border p-3">
      <div className="flex items-center gap-2">
        <Icon className={`h-4 w-4 ${color}`} />
        <p className="text-xs font-medium text-muted-foreground">{label}</p>
      </div>
      <p className={`mt-1 text-xl font-bold ${color}`}>{value}</p>
    </div>
  );
}
