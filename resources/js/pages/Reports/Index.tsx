import { router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Download, FileSpreadsheet } from 'lucide-react';
import { formatCurrency } from '@/lib/utils';

interface Props {
  report: {
    period: { from: string; to: string };
    rows: Record<string, unknown>[];
    [key: string]: unknown;
  };
  type: string;
  filters: { from: string; to: string; type: string };
}

const reportTypes = [
  { key: 'sales', label: 'Sales' },
  { key: 'agents', label: 'Agent Performance' },
  { key: 'couriers', label: 'Courier Comparison' },
  { key: 'products', label: 'Product P&L' },
  { key: 'customers', label: 'Customer Analysis' },
];

export default function ReportsIndex({ report, type, filters }: Props) {
  const rows = report.rows ?? [];
  const columns = rows.length > 0 ? Object.keys(rows[0]) : [];

  const handleDownload = () => {
    window.location.href = `/reports/download?type=${filters.type}&from=${filters.from}&to=${filters.to}`;
  };

  const formatCell = (value: unknown): string => {
    if (value === null || value === undefined) return '-';
    if (typeof value === 'number') {
      if (value >= 100 || value <= -100) return formatCurrency(value);
      if (Number.isInteger(value)) return value.toString();
      return value.toFixed(1);
    }
    return String(value);
  };

  const friendlyHeader = (key: string): string => {
    return key
      .replace(/_/g, ' ')
      .replace(/\b\w/g, (l) => l.toUpperCase())
      .replace('Id', 'ID')
      .replace('Cod', 'COD')
      .replace('Cogs', 'COGS')
      .replace('Avg', 'Avg.');
  };

  // Extract summary stats (non-rows, non-period fields)
  const summaryKeys = Object.keys(report).filter(
    (k) => k !== 'rows' && k !== 'period' && typeof report[k] !== 'object'
  );

  return (
    <AppLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Reports</h1>
            <p className="text-sm text-muted-foreground">Generate and export business reports</p>
          </div>
          <Button onClick={handleDownload} disabled={rows.length === 0}>
            <Download className="mr-2 h-4 w-4" />Download CSV
          </Button>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="p-4">
            <div className="flex flex-wrap items-center gap-3">
              {/* Report type tabs */}
              <div className="flex gap-1 bg-muted p-1 rounded-lg">
                {reportTypes.map((rt) => (
                  <button
                    key={rt.key}
                    onClick={() => router.get('/reports', { ...filters, type: rt.key }, { preserveState: true })}
                    className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                      type === rt.key ? 'bg-background shadow text-foreground' : 'text-muted-foreground hover:text-foreground'
                    }`}
                  >
                    {rt.label}
                  </button>
                ))}
              </div>

              <div className="flex-1" />

              {/* Date range */}
              <input
                type="date"
                value={filters.from}
                onChange={(e) => router.get('/reports', { ...filters, from: e.target.value }, { preserveState: true })}
                className="border rounded-lg px-3 py-2 text-sm"
              />
              <span className="text-muted-foreground text-sm">to</span>
              <input
                type="date"
                value={filters.to}
                onChange={(e) => router.get('/reports', { ...filters, to: e.target.value }, { preserveState: true })}
                className="border rounded-lg px-3 py-2 text-sm"
              />
            </div>
          </CardContent>
        </Card>

        {/* Summary stats */}
        {summaryKeys.length > 0 && (
          <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
            {summaryKeys.map((key) => (
              <Card key={key}>
                <CardContent className="p-3 text-center">
                  <p className="text-lg font-bold">{formatCell(report[key])}</p>
                  <p className="text-[10px] text-muted-foreground uppercase">{friendlyHeader(key)}</p>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* Data table */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="text-base">
              {reportTypes.find((r) => r.key === type)?.label ?? 'Report'} — {rows.length} rows
            </CardTitle>
            <Badge variant="outline">{report.period.from} to {report.period.to}</Badge>
          </CardHeader>
          <CardContent>
            {rows.length === 0 ? (
              <div className="text-center py-12 text-muted-foreground">
                <FileSpreadsheet className="h-10 w-10 mx-auto mb-3 opacity-30" />
                <p>No data for the selected period.</p>
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b">
                      {columns.map((col) => (
                        <th key={col} className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground uppercase tracking-wider whitespace-nowrap">
                          {friendlyHeader(col)}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row, i) => (
                      <tr key={i} className="border-b hover:bg-muted/30">
                        {columns.map((col) => (
                          <td key={col} className="px-3 py-2 whitespace-nowrap">
                            {formatCell(row[col])}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
