import AppLayout from '@/layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
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
import { ArrowLeft, Search, Send, CheckCircle, XCircle, Clock } from 'lucide-react';
import { format, formatDistanceToNow } from 'date-fns';
import { useState } from 'react';

interface SmsLog {
  id: number;
  phone: string;
  message: string;
  status: 'pending' | 'sent' | 'delivered' | 'failed';
  error_message: string | null;
  external_id: string | null;
  cost: number | null;
  sent_at: string | null;
  created_at: string;
  campaign?: { id: number; name: string } | null;
  waybill?: { id: number; waybill_number: string } | null;
}

interface Props {
  logs: {
    data: SmsLog[];
    current_page: number;
    last_page: number;
    total: number;
    from: number;
    to: number;
  };
  stats: {
    total: number;
    sent: number;
    failed: number;
    pending: number;
  };
  filters: {
    status?: string;
    phone?: string;
    date_from?: string;
    date_to?: string;
  };
}

const statusConfig: Record<string, { label: string; color: string; icon: React.ComponentType<any> }> = {
  pending: { label: 'Pending', color: 'secondary', icon: Clock },
  sent: { label: 'Sent', color: 'default', icon: Send },
  delivered: { label: 'Delivered', color: 'default', icon: CheckCircle },
  failed: { label: 'Failed', color: 'destructive', icon: XCircle },
};

export default function Logs({ logs, stats, filters }: Props) {
  const [localFilters, setLocalFilters] = useState(filters);

  const applyFilters = () => {
    router.get('/sms/logs', localFilters, { preserveState: true });
  };

  const clearFilters = () => {
    setLocalFilters({});
    router.get('/sms/logs');
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter') {
      applyFilters();
    }
  };

  return (
    <AppLayout>
      <Head title="SMS Logs" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link href="/sms">
              <Button variant="ghost" size="icon">
                <ArrowLeft className="h-5 w-5" />
              </Button>
            </Link>
            <div>
              <h1 className="text-2xl font-bold tracking-tight">SMS Logs</h1>
              <p className="text-muted-foreground">
                View all sent messages and their delivery status
              </p>
            </div>
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Total Messages</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.total.toLocaleString()}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-green-600">Sent</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">
                {stats.sent.toLocaleString()}
              </div>
              <p className="text-xs text-muted-foreground">
                {stats.total > 0 ? ((stats.sent / stats.total) * 100).toFixed(1) : 0}% success
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-red-600">Failed</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">
                {stats.failed.toLocaleString()}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium">Pending</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats.pending.toLocaleString()}</div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <Card>
          <CardHeader>
            <CardTitle>Filters</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-4">
              <div className="flex-1 min-w-[200px]">
                <Input
                  placeholder="Search by phone..."
                  value={localFilters.phone || ''}
                  onChange={(e) =>
                    setLocalFilters({ ...localFilters, phone: e.target.value })
                  }
                  onKeyPress={handleKeyPress}
                />
              </div>

              <Select
                value={localFilters.status || ''}
                onValueChange={(value) =>
                  setLocalFilters({ ...localFilters, status: value || undefined })
                }
              >
                <SelectTrigger className="w-[150px]">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                  <SelectItem value="sent">Sent</SelectItem>
                  <SelectItem value="delivered">Delivered</SelectItem>
                  <SelectItem value="failed">Failed</SelectItem>
                </SelectContent>
              </Select>

              <Input
                type="date"
                placeholder="From"
                className="w-[150px]"
                value={localFilters.date_from || ''}
                onChange={(e) =>
                  setLocalFilters({ ...localFilters, date_from: e.target.value })
                }
              />

              <Input
                type="date"
                placeholder="To"
                className="w-[150px]"
                value={localFilters.date_to || ''}
                onChange={(e) =>
                  setLocalFilters({ ...localFilters, date_to: e.target.value })
                }
              />

              <Button onClick={applyFilters}>
                <Search className="mr-2 h-4 w-4" />
                Search
              </Button>

              <Button variant="outline" onClick={clearFilters}>
                Clear
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Logs Table */}
        <Card>
          <CardHeader>
            <CardTitle>Message Logs</CardTitle>
            <CardDescription>
              Showing {logs.from}-{logs.to} of {logs.total} messages
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Phone</TableHead>
                  <TableHead>Message</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Campaign</TableHead>
                  <TableHead>Sent At</TableHead>
                  <TableHead>Error</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {logs.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">
                      No messages found
                    </TableCell>
                  </TableRow>
                ) : (
                  logs.data.map((log) => {
                    const status = statusConfig[log.status] || statusConfig.pending;
                    const StatusIcon = status.icon;
                    return (
                      <TableRow key={log.id}>
                        <TableCell className="font-mono">{log.phone}</TableCell>
                        <TableCell className="max-w-[200px] truncate" title={log.message}>
                          {log.message}
                        </TableCell>
                        <TableCell>
                          <Badge variant={status.color as any} className="gap-1">
                            <StatusIcon className="h-3 w-3" />
                            {status.label}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          {log.campaign ? (
                            <Link
                              href={`/sms/campaigns/${log.campaign.id}`}
                              className="text-primary hover:underline"
                            >
                              {log.campaign.name}
                            </Link>
                          ) : log.waybill ? (
                            <span className="text-muted-foreground font-mono text-xs">
                              {log.waybill.waybill_number}
                            </span>
                          ) : (
                            <span className="text-muted-foreground">-</span>
                          )}
                        </TableCell>
                        <TableCell className="text-muted-foreground">
                          {log.sent_at ? (
                            <span title={format(new Date(log.sent_at), 'PPpp')}>
                              {formatDistanceToNow(new Date(log.sent_at), { addSuffix: true })}
                            </span>
                          ) : (
                            '-'
                          )}
                        </TableCell>
                        <TableCell className="text-red-600 text-xs max-w-[150px] truncate">
                          {log.error_message || '-'}
                        </TableCell>
                      </TableRow>
                    );
                  })
                )}
              </TableBody>
            </Table>

            {/* Pagination */}
            {logs.last_page > 1 && (
              <div className="flex justify-center gap-2 mt-4">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={logs.current_page === 1}
                  onClick={() =>
                    router.get('/sms/logs', { ...filters, page: logs.current_page - 1 })
                  }
                >
                  Previous
                </Button>
                <span className="flex items-center px-4 text-sm text-muted-foreground">
                  Page {logs.current_page} of {logs.last_page}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={logs.current_page === logs.last_page}
                  onClick={() =>
                    router.get('/sms/logs', { ...filters, page: logs.current_page + 1 })
                  }
                >
                  Next
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
