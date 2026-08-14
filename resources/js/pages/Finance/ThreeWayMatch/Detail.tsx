import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, FileText, Package, ShieldX } from 'lucide-react';

interface Mismatch {
  type: string;
  severity: string;
  message: string;
  [key: string]: unknown;
}

interface LineComparison {
  po_item_id: number;
  description: string;
  sku: string | null;
  po_quantity: number;
  po_unit_price: number;
  po_line_total: number;
  grn_quantity: number;
  rejected_quantity: number;
  grn_line_total: number;
  invoice_quantity: number | null;
  invoice_unit_price: number | null;
  invoice_line_total: number | null;
  qty_variance: number | null;
  price_variance: number | null;
}

interface MatchDetail {
  match: {
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
    mismatches: Mismatch[] | null;
    matched_by: string | null;
    matched_at: string | null;
    updated_at: string | null;
  };
  po: {
    id: number;
    po_number: string;
    status: string;
    supplier: string;
    total_amount: number;
    subtotal: number;
    tax_amount: number;
  };
  grns: Array<{
    id: number;
    grn_number: string;
    received_at: string | null;
    status: string;
  }>;
  supplier_invoice: {
    id: number;
    ref: string;
    status: string;
    total_amount: number;
  } | null;
  line_comparison: LineComparison[];
  mismatches: Mismatch[];
}

interface Props {
  detail: MatchDetail;
}

const STATUS_BADGE: Record<string, { color: string; label: string }> = {
  MATCHED: { color: 'text-success', label: 'Matched' },
  MISMATCH: { color: 'text-warning', label: 'Mismatch' },
  BLOCKED: { color: 'text-destructive', label: 'Blocked' },
  PENDING: { color: 'text-muted-foreground', label: 'Pending' },
};

const SEVERITY_COLOR: Record<string, string> = {
  high: 'text-destructive',
  medium: 'text-warning',
  low: 'text-muted-foreground',
};

const MISMATCH_TYPE_LABEL: Record<string, string> = {
  missing_grn: 'Missing GRN',
  quantity_short: 'Quantity Short',
  quantity_over: 'Quantity Over',
  total_mismatch: 'Total Mismatch',
  grn_invoice_mismatch: 'GRN vs Invoice Mismatch',
  line_quantity_mismatch: 'Line Quantity Mismatch',
  line_price_mismatch: 'Line Price Mismatch',
  missing_invoice_line: 'Missing Invoice Line',
  extra_invoice_line: 'Extra Invoice Line',
};

export default function ThreeWayMatchDetail({ detail }: Props) {
  const { match: m, po, grns, supplier_invoice, line_comparison, mismatches } = detail;
  const badge = STATUS_BADGE[m.status] ?? STATUS_BADGE.PENDING;

  return (
    <>
      <Head title={`Three-Way Match — ${m.po_number}`} />
      <div className="space-y-6 p-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <Link
              href="/finance/three-way-match"
              className="text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-5 w-5" />
            </Link>
            <div>
              <h1 className="text-2xl font-bold">Three-Way Match — {m.po_number}</h1>
              <p className="text-sm text-muted-foreground">
                {po.supplier} · PO Status: {po.status}
              </p>
            </div>
          </div>
          <span className={`inline-flex items-center gap-2 text-lg font-semibold ${badge.color}`}>
            {m.status === 'MATCHED' ? (
              <CheckCircle2 className="h-5 w-5" />
            ) : m.status === 'BLOCKED' ? (
              <ShieldX className="h-5 w-5" />
            ) : (
              <AlertTriangle className="h-5 w-5" />
            )}
            {badge.label}
          </span>
        </div>

        {/* Summary Cards */}
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <SummaryCard label="PO Total" value={`₱${po.total_amount.toLocaleString()}`} />
          <SummaryCard label="GRN Total" value={`₱${m.grn_total.toLocaleString()}`} />
          <SummaryCard
            label="Invoice Total"
            value={m.invoice_total > 0 ? `₱${m.invoice_total.toLocaleString()}` : '—'}
          />
          <SummaryCard
            label="Variance"
            value={m.variance_amount !== 0 ? `₱${m.variance_amount.toLocaleString()}` : '₱0.00'}
            color={m.variance_amount !== 0 ? 'text-warning' : 'text-success'}
          />
        </div>

        {/* Mismatches */}
        {mismatches.length > 0 && (
          <div className="rounded-lg border border-warning/30 bg-warning/5 p-4">
            <h2 className="mb-3 flex items-center gap-2 text-lg font-semibold text-warning">
              <AlertTriangle className="h-5 w-5" />
              {mismatches.length} Mismatch(es) Detected
            </h2>
            <div className="space-y-2">
              {mismatches.map((mm, i) => (
                <div key={i} className="flex items-start gap-3 rounded-md border bg-background p-3">
                  <span
                    className={`mt-0.5 text-xs font-bold uppercase ${SEVERITY_COLOR[mm.severity] ?? 'text-muted-foreground'}`}
                  >
                    {mm.severity}
                  </span>
                  <div className="flex-1">
                    <p className="text-sm font-medium">{MISMATCH_TYPE_LABEL[mm.type] ?? mm.type}</p>
                    <p className="text-sm text-muted-foreground">{mm.message}</p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}

        {mismatches.length === 0 && m.status === 'MATCHED' && (
          <div className="rounded-lg border border-success/30 bg-success/5 p-4">
            <div className="flex items-center gap-2 text-success">
              <CheckCircle2 className="h-5 w-5" />
              <p className="font-medium">
                All three documents match — PO, GRN, and Supplier Invoice are consistent.
              </p>
            </div>
          </div>
        )}

        {/* GRNs */}
        <div className="rounded-lg border">
          <div className="border-b p-4">
            <h2 className="flex items-center gap-2 text-lg font-semibold">
              <Package className="h-5 w-5" />
              Receiving Reports (GRN)
            </h2>
          </div>
          {grns.length === 0 ? (
            <div className="p-4 text-sm text-muted-foreground">No confirmed GRNs for this PO.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b bg-muted/50">
                  <tr>
                    <th className="px-4 py-2 text-left font-medium">GRN Number</th>
                    <th className="px-4 py-2 text-left font-medium">Received At</th>
                    <th className="px-4 py-2 text-left font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {grns.map((g) => (
                    <tr key={g.id} className="border-b last:border-0">
                      <td className="px-4 py-2 font-medium">{g.grn_number}</td>
                      <td className="px-4 py-2">
                        {g.received_at ? new Date(g.received_at).toLocaleString() : '—'}
                      </td>
                      <td className="px-4 py-2">{g.status}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Supplier Invoice */}
        {supplier_invoice && (
          <div className="rounded-lg border">
            <div className="border-b p-4">
              <h2 className="flex items-center gap-2 text-lg font-semibold">
                <FileText className="h-5 w-5" />
                Supplier Invoice
              </h2>
            </div>
            <div className="grid grid-cols-3 gap-4 p-4 text-sm">
              <div>
                <p className="text-xs text-muted-foreground">Reference</p>
                <p className="font-medium">{supplier_invoice.ref}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Status</p>
                <p className="font-medium">{supplier_invoice.status}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Total Amount</p>
                <p className="font-medium">₱{supplier_invoice.total_amount.toLocaleString()}</p>
              </div>
            </div>
          </div>
        )}

        {/* Line Comparison Table */}
        <div className="rounded-lg border">
          <div className="border-b p-4">
            <h2 className="text-lg font-semibold">Line-by-Line Comparison</h2>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b bg-muted/50">
                <tr>
                  <th className="px-4 py-2 text-left font-medium">Item</th>
                  <th className="px-4 py-2 text-right font-medium">PO Qty</th>
                  <th className="px-4 py-2 text-right font-medium">PO Price</th>
                  <th className="px-4 py-2 text-right font-medium">GRN Qty</th>
                  <th className="px-4 py-2 text-right font-medium">Rejected</th>
                  <th className="px-4 py-2 text-right font-medium">Inv Qty</th>
                  <th className="px-4 py-2 text-right font-medium">Inv Price</th>
                  <th className="px-4 py-2 text-right font-medium">Qty Var</th>
                  <th className="px-4 py-2 text-right font-medium">Price Var</th>
                </tr>
              </thead>
              <tbody>
                {line_comparison.map((line) => (
                  <tr key={line.po_item_id} className="border-b last:border-0 hover:bg-muted/30">
                    <td className="px-4 py-2">
                      <p className="font-medium">{line.description}</p>
                      {line.sku && <p className="text-xs text-muted-foreground">{line.sku}</p>}
                    </td>
                    <td className="px-4 py-2 text-right">{line.po_quantity}</td>
                    <td className="px-4 py-2 text-right">₱{line.po_unit_price.toFixed(2)}</td>
                    <td className="px-4 py-2 text-right">{line.grn_quantity}</td>
                    <td className="px-4 py-2 text-right">
                      {line.rejected_quantity > 0 ? (
                        <span className="text-destructive">{line.rejected_quantity}</span>
                      ) : (
                        '0'
                      )}
                    </td>
                    <td className="px-4 py-2 text-right">{line.invoice_quantity ?? '—'}</td>
                    <td className="px-4 py-2 text-right">
                      {line.invoice_unit_price != null
                        ? `₱${line.invoice_unit_price.toFixed(2)}`
                        : '—'}
                    </td>
                    <td
                      className={`px-4 py-2 text-right font-medium ${line.qty_variance !== null && line.qty_variance !== 0 ? 'text-warning' : ''}`}
                    >
                      {line.qty_variance !== null
                        ? line.qty_variance > 0
                          ? `+${line.qty_variance}`
                          : line.qty_variance
                        : '—'}
                    </td>
                    <td
                      className={`px-4 py-2 text-right font-medium ${line.price_variance !== null && line.price_variance !== 0 ? 'text-warning' : ''}`}
                    >
                      {line.price_variance !== null
                        ? line.price_variance > 0
                          ? `+₱${line.price_variance.toFixed(2)}`
                          : `₱${line.price_variance.toFixed(2)}`
                        : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Match Metadata */}
        <div className="rounded-lg border p-4 text-sm">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
              <p className="text-xs text-muted-foreground">Match Level</p>
              <p className="font-medium">{m.match_level}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Matched By</p>
              <p className="font-medium">{m.matched_by ?? '—'}</p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Matched At</p>
              <p className="font-medium">
                {m.matched_at ? new Date(m.matched_at).toLocaleString() : '—'}
              </p>
            </div>
            <div>
              <p className="text-xs text-muted-foreground">Last Updated</p>
              <p className="font-medium">
                {m.updated_at ? new Date(m.updated_at).toLocaleString() : '—'}
              </p>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

function SummaryCard({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <div className="rounded-lg border p-4">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className={`mt-1 text-xl font-bold ${color ?? ''}`}>{value}</p>
    </div>
  );
}
