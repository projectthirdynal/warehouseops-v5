import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  ArrowLeft,
  CheckCircle,
  XCircle,
  ChevronDown,
  ChevronUp,
  FileText,
} from 'lucide-react';
import { formatDateTime } from '@/lib/utils';
import type { CourierProvider, CourierApiLog, PaginatedResponse, PageProps } from '@/types';

interface Props extends PageProps {
  provider: CourierProvider;
  logs: PaginatedResponse<CourierApiLog>;
  filters: { action?: string; success?: string };
}

export default function CourierLogs({ provider, logs, filters }: Props) {
  const [expanded, setExpanded] = useState<number | null>(null);

  const actions = ['create_order', 'cancel_order', 'query_tracking', 'webhook', 'test_connection'];

  const applyFilter = (key: string, value: string | null) => {
    const params: Record<string, string> = {};
    if (filters.action && key !== 'action') params.action = filters.action;
    if (filters.success && key !== 'success') params.success = filters.success;
    if (value) params[key] = value;

    router.get(`/couriers/${provider.id}/logs`, params, { preserveState: true });
  };

  return (
    <AppLayout>
      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center gap-3">
          <Link href="/couriers">
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-xl font-bold flex items-center gap-2">
              <FileText className="h-5 w-5" />
              {provider.name} — API Logs
            </h1>
            <p className="text-sm text-muted-foreground">{logs.total} total log entries</p>
          </div>
        </div>

        {/* Filters */}
        <div className="flex items-center gap-2 flex-wrap">
          <Button
            variant={!filters.action ? 'default' : 'outline'}
            size="sm"
            onClick={() => applyFilter('action', null)}
          >
            All
          </Button>
          {actions.map((action) => (
            <Button
              key={action}
              variant={filters.action === action ? 'default' : 'outline'}
              size="sm"
              onClick={() => applyFilter('action', action)}
            >
              {action.replace(/_/g, ' ')}
            </Button>
          ))}
          <div className="w-px h-6 bg-border mx-1" />
          <Button
            variant={filters.success === '1' ? 'default' : 'outline'}
            size="sm"
            onClick={() => applyFilter('success', filters.success === '1' ? null : '1')}
          >
            <CheckCircle className="mr-1 h-3.5 w-3.5" />
            Success
          </Button>
          <Button
            variant={filters.success === '0' ? 'default' : 'outline'}
            size="sm"
            onClick={() => applyFilter('success', filters.success === '0' ? null : '0')}
          >
            <XCircle className="mr-1 h-3.5 w-3.5" />
            Failed
          </Button>
        </div>

        {/* Log entries */}
        <div className="rounded-xl border bg-card shadow-sm divide-y">
          {logs.data.length === 0 ? (
            <div className="p-8 text-center text-sm text-muted-foreground">
              No logs found matching filters.
            </div>
          ) : (
            logs.data.map((log) => (
              <div key={log.id}>
                <button
                  type="button"
                  className="w-full flex items-center gap-3 px-5 py-3 text-sm hover:bg-accent/30 transition-colors text-left"
                  onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                >
                  {log.is_success ? (
                    <CheckCircle className="h-4 w-4 text-green-500 shrink-0" />
                  ) : (
                    <XCircle className="h-4 w-4 text-red-500 shrink-0" />
                  )}
                  <Badge variant="outline" className="text-xs font-mono shrink-0">
                    {log.action.replace(/_/g, ' ')}
                  </Badge>
                  <Badge variant={log.direction === 'inbound' ? 'secondary' : 'outline'} className="text-[10px]">
                    {log.direction}
                  </Badge>
                  {log.http_status && (
                    <span className={`text-xs font-mono ${log.http_status >= 400 ? 'text-red-500' : 'text-muted-foreground'}`}>
                      HTTP {log.http_status}
                    </span>
                  )}
                  {log.response_time_ms && (
                    <span className="text-xs text-muted-foreground">{log.response_time_ms}ms</span>
                  )}
                  {log.error_message && (
                    <span className="text-xs text-red-500 truncate max-w-[200px]">{log.error_message}</span>
                  )}
                  <span className="ml-auto text-xs text-muted-foreground shrink-0">
                    {formatDateTime(log.created_at)}
                  </span>
                  {expanded === log.id ? (
                    <ChevronUp className="h-4 w-4 text-muted-foreground shrink-0" />
                  ) : (
                    <ChevronDown className="h-4 w-4 text-muted-foreground shrink-0" />
                  )}
                </button>
                {expanded === log.id && (
                  <div className="px-5 pb-4 space-y-3">
                    {log.endpoint && (
                      <div>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground mb-1">Endpoint</p>
                        <p className="font-mono text-xs bg-muted p-2 rounded">{log.endpoint}</p>
                      </div>
                    )}
                    {log.request_data && (
                      <div>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground mb-1">Request</p>
                        <pre className="font-mono text-xs bg-muted p-3 rounded overflow-x-auto max-h-48">
                          {JSON.stringify(log.request_data, null, 2)}
                        </pre>
                      </div>
                    )}
                    {log.response_data && (
                      <div>
                        <p className="text-[10px] uppercase tracking-wide text-muted-foreground mb-1">Response</p>
                        <pre className="font-mono text-xs bg-muted p-3 rounded overflow-x-auto max-h-48">
                          {JSON.stringify(log.response_data, null, 2)}
                        </pre>
                      </div>
                    )}
                  </div>
                )}
              </div>
            ))
          )}
        </div>

        {/* Pagination */}
        {logs.last_page > 1 && (
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">
              Showing {logs.from}–{logs.to} of {logs.total}
            </span>
            <div className="flex gap-1">
              {Array.from({ length: logs.last_page }, (_, i) => i + 1).map((page) => (
                <Button
                  key={page}
                  variant={page === logs.current_page ? 'default' : 'outline'}
                  size="sm"
                  onClick={() =>
                    router.get(
                      `/couriers/${provider.id}/logs`,
                      { ...filters, page: String(page) },
                      { preserveState: true }
                    )
                  }
                >
                  {page}
                </Button>
              ))}
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
