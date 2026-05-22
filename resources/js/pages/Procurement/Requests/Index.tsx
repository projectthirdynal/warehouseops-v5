import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import { FileText, Plus, FileCheck2, Hourglass, FileX, FileEdit } from 'lucide-react';
import { formatDate } from '@/lib/utils';
import type { PaginatedResponse } from '@/types';

interface PrRow {
  id: number;
  pr_number: string;
  status: string;
  priority: string;
  department?: string;
  needed_by_date?: string;
  estimated_total: number;
  items_count: number;
  requester?: { id: number; name: string };
  approver?: { id: number; name: string };
  created_at: string;
}

interface Props {
  requests: PaginatedResponse<PrRow>;
  stats: { draft: number; submitted: number; approved: number; converted: number };
  filters: { status?: string; priority?: string; search?: string };
}

const STATUS_COLOR: Record<string, string> = {
  DRAFT:     'bg-gray-100 text-gray-700',
  SUBMITTED: 'bg-blue-100 text-blue-700',
  APPROVED:  'bg-green-100 text-green-700',
  CONVERTED: 'bg-emerald-100 text-emerald-700',
  REJECTED:  'bg-red-100 text-red-700',
  CANCELLED: 'bg-orange-100 text-orange-700',
};

const PRIORITY_COLOR: Record<string, string> = {
  LOW:    'bg-gray-100 text-gray-700',
  NORMAL: 'bg-blue-100 text-blue-700',
  URGENT: 'bg-red-100 text-red-700',
};

export default function PrIndex({ requests, stats, filters }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');

  function applyFilters(o: Record<string, string>) {
    router.get('/procurement/requests', { ...filters, ...o }, { preserveState: true, replace: true });
  }

  return (
    <AppLayout>
      <Head title="Purchase Requests" />
      <div className="space-y-6 p-6">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Purchase Requests</h1>
            <p className="text-sm text-muted-foreground">Internal requests that become purchase orders.</p>
          </div>
          <Link href="/procurement/requests/create">
            <Button><Plus className="mr-2 h-4 w-4" />New PR</Button>
          </Link>
        </div>

        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <StatCard icon={<FileEdit className="h-4 w-4 text-gray-500" />}    label="Draft"     value={stats.draft} />
          <StatCard icon={<Hourglass className="h-4 w-4 text-blue-500" />}    label="Submitted" value={stats.submitted} />
          <StatCard icon={<FileCheck2 className="h-4 w-4 text-green-500" />}  label="Approved"  value={stats.approved} />
          <StatCard icon={<FileX className="h-4 w-4 text-emerald-500" />}     label="Converted" value={stats.converted} />
        </div>

        <div className="flex flex-wrap gap-2">
          <form onSubmit={(e) => { e.preventDefault(); applyFilters({ search, page: '1' }); }} className="flex gap-2">
            <Input placeholder="PR # search..." value={search} onChange={e => setSearch(e.target.value)} className="w-56" />
            <Button type="submit" variant="secondary" size="sm">Search</Button>
          </form>
          <Select value={filters.status ?? 'all'} onValueChange={(v) => applyFilters({ status: v === 'all' ? '' : v, page: '1' })}>
            <SelectTrigger className="w-44"><SelectValue placeholder="Status" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Statuses</SelectItem>
              <SelectItem value="DRAFT">Draft</SelectItem>
              <SelectItem value="SUBMITTED">Submitted</SelectItem>
              <SelectItem value="APPROVED">Approved</SelectItem>
              <SelectItem value="CONVERTED">Converted</SelectItem>
              <SelectItem value="REJECTED">Rejected</SelectItem>
            </SelectContent>
          </Select>
          <Select value={filters.priority ?? 'all'} onValueChange={(v) => applyFilters({ priority: v === 'all' ? '' : v, page: '1' })}>
            <SelectTrigger className="w-40"><SelectValue placeholder="Priority" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Priorities</SelectItem>
              <SelectItem value="LOW">Low</SelectItem>
              <SelectItem value="NORMAL">Normal</SelectItem>
              <SelectItem value="URGENT">Urgent</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>PR #</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Priority</TableHead>
                <TableHead>Department</TableHead>
                <TableHead className="text-right">Items</TableHead>
                <TableHead className="text-right">Est. Total</TableHead>
                <TableHead>Needed by</TableHead>
                <TableHead>Requester</TableHead>
                <TableHead>Created</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {requests.data.length === 0 ? (
                <TableRow><TableCell colSpan={9} className="py-12 text-center text-muted-foreground">
                  <FileText className="mx-auto mb-2 h-8 w-8 opacity-30" />No purchase requests yet.
                </TableCell></TableRow>
              ) : requests.data.map(pr => (
                <TableRow key={pr.id}>
                  <TableCell>
                    <Link href={`/procurement/requests/${pr.id}`} className="font-mono text-sm font-medium text-primary hover:underline">
                      {pr.pr_number}
                    </Link>
                  </TableCell>
                  <TableCell><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOR[pr.status]}`}>{pr.status}</span></TableCell>
                  <TableCell><span className={`rounded-full px-2 py-0.5 text-xs font-medium ${PRIORITY_COLOR[pr.priority]}`}>{pr.priority}</span></TableCell>
                  <TableCell className="text-sm">{pr.department ?? '—'}</TableCell>
                  <TableCell className="text-right text-sm">{pr.items_count}</TableCell>
                  <TableCell className="text-right font-medium">₱{Number(pr.estimated_total).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</TableCell>
                  <TableCell className="text-sm">{pr.needed_by_date ? formatDate(pr.needed_by_date) : '—'}</TableCell>
                  <TableCell className="text-sm">{pr.requester?.name ?? '—'}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{formatDate(pr.created_at)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>

        {requests.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: requests.last_page }, (_, i) => i + 1).map(p => (
              <Button key={p} size="sm" variant={p === requests.current_page ? 'default' : 'outline'}
                onClick={() => applyFilters({ page: String(p) })}>{p}</Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}

function StatCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: number }) {
  return (
    <Card>
      <CardHeader className="pb-2"><CardTitle className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</CardTitle></CardHeader>
      <CardContent>
        <div className="flex items-center gap-2">
          {icon}<span className="text-2xl font-bold">{value}</span>
        </div>
      </CardContent>
    </Card>
  );
}
