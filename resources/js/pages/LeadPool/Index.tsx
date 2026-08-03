import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Search,
  Filter,
  Users,
  Clock,
  Pause,
  AlertTriangle,
  Phone,
  ArrowUpDown,
  UserPlus,
  TrendingUp,
  CheckCircle,
  Inbox,
  RefreshCw,
  Archive,
  XCircle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { DistributionModal } from '@/components/leads/DistributionModal';
import { BulkReassignModal } from '@/components/leads/BulkReassignModal';
import { BulkActionModal } from '@/components/leads/BulkActionModal';
import type { PaginatedResponse } from '@/types';
import { formatDate } from '@/lib/utils';

interface PoolLead {
  id: number;
  name: string;
  phone: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  source: string;
  product_name: string | null;
  product_brand: string | null;
  amount: number | null;
  status: string;
  sales_status: string;
  pool_status: 'AVAILABLE' | 'ASSIGNED' | 'COOLDOWN' | 'EXHAUSTED';
  total_cycles: number;
  is_exhausted: boolean;
  cooldown_until: string | null;
  assigned_to: number | null;
  assigned_agent: { id: number; name: string } | null;
  customer: {
    id: number;
    total_orders: number;
    success_rate: number;
    is_blacklisted: boolean;
  } | null;
  created_at: string;
}

interface Agent {
  id: number;
  name: string;
  active_leads: number;
  max_active_cycles: number;
  max_daily_leads: number;
  product_skills: string[];
}
interface SourceOption {
  value: string;
  label: string;
}

type ViewMode = 'pool' | 'imported' | 'all';

interface CapacityAlert {
  level: 'low' | 'high';
  count: number;
  threshold: number;
  source: string | null;
}

interface Props {
  leads: PaginatedResponse<PoolLead>;
  stats: any;
  agents: Agent[];
  capacityAlerts?: CapacityAlert[];
  filters: {
    pool_status?: string;
    source?: string;
    city?: string;
    product_name?: string;
    search?: string;
    view_mode?: ViewMode;
  };
  viewMode: ViewMode;
  sourceOptions: SourceOption[];
  productOptions: string[];
}

const poolStatusConfig: Record<
  string,
  { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
  AVAILABLE: { label: 'Available', variant: 'default' },
  ASSIGNED: { label: 'Assigned', variant: 'secondary' },
  COOLDOWN: { label: 'Cooldown', variant: 'outline' },
  EXHAUSTED: { label: 'Exhausted', variant: 'destructive' },
};

const statusColorMap: Record<string, string> = {
  NEW: 'bg-info/10 text-info',
  CALLING: 'bg-warning/10 text-warning',
  NO_ANSWER: 'bg-muted text-foreground',
  REJECT: 'bg-destructive/10 text-destructive',
  CALLBACK: 'bg-primary/10 text-primary',
  SALE: 'bg-success/10 text-success',
  REORDER: 'bg-success/10 text-success',
  CANCELLED: 'bg-muted text-foreground',
};

const salesVariantMap: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  NEW: 'secondary',
  CONTACTED: 'outline',
  AGENT_CONFIRMED: 'default',
  QA_PENDING: 'secondary',
  QA_APPROVED: 'default',
  QA_REJECTED: 'destructive',
  OPS_APPROVED: 'default',
  CANCELLED: 'destructive',
  WAYBILL_CREATED: 'default',
};

const tabs: { key: ViewMode; label: string }[] = [
  { key: 'pool', label: 'Distribution Pool' },
  { key: 'imported', label: 'Imported Leads' },
  { key: 'all', label: 'All Leads' },
];

export default function LeadPoolIndex({
  leads,
  stats,
  agents,
  capacityAlerts = [],
  filters,
  viewMode,
  sourceOptions: _sourceOptions,
  productOptions,
}: Props) {
  const { flash } = usePage().props as any;
  const bulkActionErrors: string[] = flash?.bulkActionErrors ?? [];

  const [search, setSearch] = useState(filters?.search || '');
  const [statusFilter, setStatusFilter] = useState(filters?.pool_status || 'all');
  const [selectedLeads, setSelectedLeads] = useState<number[]>([]);
  const [isDistributeOpen, setIsDistributeOpen] = useState(false);
  const [isReassignOpen, setIsReassignOpen] = useState(false);
  const [isRecycleOpen, setIsRecycleOpen] = useState(false);
  const [isArchiveOpen, setIsArchiveOpen] = useState(false);
  const isPoolView = viewMode === 'pool';
  const showCheckboxes = isPoolView || viewMode === 'imported';

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.get(
      '/lead-pool',
      {
        search,
        view_mode: viewMode,
        pool_status: statusFilter !== 'all' ? statusFilter : undefined,
      },
      { preserveState: true }
    );
  };

  const toggleLead = (leadId: number) => {
    setSelectedLeads((prev) =>
      prev.includes(leadId) ? prev.filter((id) => id !== leadId) : [...prev, leadId]
    );
  };

  const toggleAll = () => {
    if (selectedLeads.length === leads.data.length) {
      setSelectedLeads([]);
    } else {
      setSelectedLeads(leads.data.map((l) => l.id));
    }
  };

  const handlePageChange = (page: number) => {
    router.get(
      '/lead-pool',
      {
        page,
        view_mode: viewMode,
        search: filters?.search,
        pool_status: filters?.pool_status,
      },
      { preserveState: true }
    );
  };

  const switchView = (mode: ViewMode) => {
    setSelectedLeads([]);
    router.get('/lead-pool', { view_mode: mode }, { preserveState: true });
  };

  return (
    <AppLayout>
      <Head title="Leads & Pool" />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">
              Leads &amp; Distribution
            </h1>
            <p className="text-muted-foreground">View pool, imported, or all leads in one place</p>
          </div>
          {showCheckboxes && selectedLeads.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {isPoolView && (
                <Button onClick={() => setIsDistributeOpen(true)}>
                  <UserPlus className="mr-1.5 h-4 w-4" />
                  Distribute {selectedLeads.length}
                </Button>
              )}
              <Button variant="outline" onClick={() => setIsReassignOpen(true)}>
                <UserPlus className="mr-1.5 h-4 w-4" />
                Reassign {selectedLeads.length}
              </Button>
              <Button variant="outline" onClick={() => setIsRecycleOpen(true)}>
                <RefreshCw className="mr-1.5 h-4 w-4" />
                Recycle {selectedLeads.length}
              </Button>
              <Button variant="outline" onClick={() => setIsArchiveOpen(true)}>
                <Archive className="mr-1.5 h-4 w-4" />
                Archive {selectedLeads.length}
              </Button>
            </div>
          )}
        </div>

        {/* Bulk Action Errors */}
        {bulkActionErrors.length > 0 && (
          <div className="space-y-2">
            <div className="flex items-center gap-2 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-warning">
              <XCircle className="h-4 w-4 flex-shrink-0" />
              <span>{bulkActionErrors.length} lead(s) could not be processed:</span>
            </div>
            <ul className="space-y-1 text-xs text-muted-foreground pl-1 max-h-32 overflow-y-auto">
              {bulkActionErrors.map((err, i) => (
                <li key={i}>{err}</li>
              ))}
            </ul>
          </div>
        )}

        {/* Capacity Alerts */}
        {isPoolView && capacityAlerts.length > 0 && (
          <div className="space-y-2">
            {capacityAlerts.map((alert, i) => (
              <div
                key={i}
                className={`flex items-center gap-2 rounded-md border px-3 py-2 text-sm ${
                  alert.level === 'low'
                    ? 'border-destructive/30 bg-destructive/10 text-destructive'
                    : 'border-warning/30 bg-warning/10 text-warning'
                }`}
              >
                <AlertTriangle className="h-4 w-4 flex-shrink-0" />
                <span>
                  {alert.level === 'low' ? 'Low availability' : 'Overstocked'}
                  {alert.source ? ` for source "${alert.source}"` : ' in the overall pool'}:{' '}
                  <strong>{alert.count}</strong> leads (threshold {alert.threshold})
                  {alert.level === 'low'
                    ? ' — consider importing more leads.'
                    : ' — consider distributing to agents.'}
                </span>
              </div>
            ))}
          </div>
        )}

        {/* View Tabs */}
        <div className="flex gap-2 border-b pb-2">
          {tabs.map((tab) => (
            <button
              key={tab.key}
              onClick={() => switchView(tab.key)}
              className={`px-4 py-2 text-sm font-medium rounded-t-md transition-colors ${
                viewMode === tab.key
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          {viewMode === 'pool' && (
            <>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Available
                  </CardTitle>
                  <Users className="h-4 w-4 text-success" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-success">
                    {stats?.available || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Assigned
                  </CardTitle>
                  <Clock className="h-4 w-4 text-info" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-info">
                    {stats?.assigned || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Cooldown
                  </CardTitle>
                  <Pause className="h-4 w-4 text-warning" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-warning">
                    {stats?.cooldown || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Exhausted
                  </CardTitle>
                  <AlertTriangle className="h-4 w-4 text-destructive" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-destructive">
                    {stats?.exhausted || 0}
                  </div>
                </CardContent>
              </Card>
            </>
          )}
          {viewMode === 'imported' && (
            <>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Total Imported
                  </CardTitle>
                  <Inbox className="h-4 w-4 text-info" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-info">
                    {stats?.total || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Available
                  </CardTitle>
                  <Users className="h-4 w-4 text-success" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-success">
                    {stats?.available || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Assigned
                  </CardTitle>
                  <Clock className="h-4 w-4 text-info" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-info">
                    {stats?.assigned || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Cooldown
                  </CardTitle>
                  <Pause className="h-4 w-4 text-warning" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-warning">
                    {stats?.cooldown || 0}
                  </div>
                </CardContent>
              </Card>
            </>
          )}
          {viewMode === 'all' && (
            <>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Total Leads
                  </CardTitle>
                  <Users className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display">{stats?.total || 0}</div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">New</CardTitle>
                  <Clock className="h-4 w-4 text-info" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-info">{stats?.new || 0}</div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    In Progress
                  </CardTitle>
                  <TrendingUp className="h-4 w-4 text-warning" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-warning">
                    {stats?.in_progress || 0}
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">
                    Converted
                  </CardTitle>
                  <CheckCircle className="h-4 w-4 text-success" />
                </CardHeader>
                <CardContent>
                  <div className="text-xl font-bold font-display text-success">
                    {stats?.converted || 0}
                  </div>
                </CardContent>
              </Card>
            </>
          )}
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <form onSubmit={handleSearch} className="flex flex-col gap-4 md:flex-row">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="text"
                  placeholder="Search by name, phone, or city..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background pl-10 pr-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="w-full md:w-[180px]">
                  <SelectValue placeholder="Pool Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="AVAILABLE">Available</SelectItem>
                  <SelectItem value="ASSIGNED">Assigned</SelectItem>
                  <SelectItem value="COOLDOWN">Cooldown</SelectItem>
                  <SelectItem value="EXHAUSTED">Exhausted</SelectItem>
                </SelectContent>
              </Select>
              <Select
                value={filters?.source || 'all'}
                onValueChange={(v) =>
                  router.get(
                    '/lead-pool',
                    {
                      source: v !== 'all' ? v : undefined,
                      view_mode: viewMode,
                      pool_status: statusFilter !== 'all' ? statusFilter : undefined,
                    },
                    { preserveState: true }
                  )
                }
              >
                <SelectTrigger className="w-full md:w-[180px]">
                  <SelectValue placeholder="Source" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Sources</SelectItem>
                  {_sourceOptions.map((opt) => (
                    <SelectItem key={opt.value} value={opt.value}>
                      {opt.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Button type="submit">
                <Filter className="mr-1.5 h-4 w-4" />
                Filter
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    {showCheckboxes && (
                      <th className="h-12 px-4 text-left align-middle">
                        <Checkbox
                          checked={
                            leads?.data?.length > 0 && selectedLeads.length === leads.data.length
                          }
                          onCheckedChange={toggleAll}
                        />
                      </th>
                    )}
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      <button className="flex items-center gap-1 hover:text-foreground">
                        Name <ArrowUpDown className="h-4 w-4" />
                      </button>
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Phone
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Location
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Product
                    </th>
                    {viewMode === 'all' && (
                      <>
                        <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                          Status
                        </th>
                        <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                          Sales
                        </th>
                        <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                          Agent
                        </th>
                      </>
                    )}
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Pool Status
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Cycles
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {leads?.data?.length > 0 ? (
                    leads.data.map((lead) => {
                      const poolCfg = poolStatusConfig[lead.pool_status];
                      return (
                        <tr key={lead.id} className="border-b transition-colors hover:bg-muted/50">
                          {showCheckboxes && (
                            <td className="p-4 align-middle">
                              <Checkbox
                                checked={selectedLeads.includes(lead.id)}
                                onCheckedChange={() => toggleLead(lead.id)}
                              />
                            </td>
                          )}
                          <td className="p-4 align-middle">
                            <div className="font-medium">{lead.name}</div>
                            <div className="text-xs text-muted-foreground">{lead.source}</div>
                          </td>
                          <td className="p-4 align-middle">
                            <div className="flex items-center gap-2">
                              <Phone className="h-4 w-4 text-muted-foreground" />
                              <span className="font-mono text-sm">{lead.phone}</span>
                            </div>
                          </td>
                          <td className="p-4 align-middle text-sm">
                            {lead.city && lead.state
                              ? `${lead.city}, ${lead.state}`
                              : lead.city || lead.state || '-'}
                          </td>
                          <td className="p-4 align-middle text-sm">
                            {lead.product_name || '-'}
                            {lead.amount && (
                              <div className="text-xs text-success">
                                ₱{lead.amount.toLocaleString()}
                              </div>
                            )}
                          </td>
                          {viewMode === 'all' && (
                            <>
                              <td className="p-4 align-middle">
                                <span
                                  className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColorMap[lead.status] || 'bg-muted text-foreground'}`}
                                >
                                  {lead.status}
                                </span>
                              </td>
                              <td className="p-4 align-middle">
                                <Badge variant={salesVariantMap[lead.sales_status] || 'secondary'}>
                                  {lead.sales_status}
                                </Badge>
                              </td>
                              <td className="p-4 align-middle text-sm">
                                {lead.assigned_agent?.name || (
                                  <span className="text-muted-foreground">-</span>
                                )}
                              </td>
                            </>
                          )}
                          <td className="p-4 align-middle">
                            <Badge variant={poolCfg?.variant || 'secondary'}>
                              {poolCfg?.label || lead.pool_status}
                            </Badge>
                            {lead.pool_status === 'COOLDOWN' && lead.cooldown_until && (
                              <div className="text-xs text-muted-foreground mt-1">
                                Until {formatDate(lead.cooldown_until)}
                              </div>
                            )}
                          </td>
                          <td className="p-4 align-middle">
                            <Badge variant="outline">{lead.total_cycles}</Badge>
                          </td>
                        </tr>
                      );
                    })
                  ) : (
                    <tr>
                      <td
                        colSpan={viewMode === 'all' ? 9 : showCheckboxes ? 7 : 6}
                        className="h-24 text-center text-muted-foreground"
                      >
                        No leads found
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {leads?.last_page > 1 && (
              <div className="flex items-center justify-between border-t px-4 py-4">
                <div className="text-sm text-muted-foreground">
                  Showing {leads.from} to {leads.to} of {leads.total} results
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={leads.current_page === 1}
                    onClick={() => handlePageChange(leads.current_page - 1)}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={leads.current_page === leads.last_page}
                    onClick={() => handlePageChange(leads.current_page + 1)}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Distribution Modal */}
      {isPoolView && (
        <DistributionModal
          isOpen={isDistributeOpen}
          onClose={() => setIsDistributeOpen(false)}
          selectedLeadIds={selectedLeads}
          agents={agents}
          productOptions={productOptions}
        />
      )}

      {/* Bulk Reassign Modal */}
      <BulkReassignModal
        isOpen={isReassignOpen}
        onClose={() => setIsReassignOpen(false)}
        selectedLeadIds={selectedLeads}
        agents={agents}
      />

      {/* Bulk Recycle Modal */}
      <BulkActionModal
        isOpen={isRecycleOpen}
        onClose={() => setIsRecycleOpen(false)}
        selectedLeadIds={selectedLeads}
        action="recycle"
        endpoint="/lead-pool/bulk-recycle"
      />

      {/* Bulk Archive Modal */}
      <BulkActionModal
        isOpen={isArchiveOpen}
        onClose={() => setIsArchiveOpen(false)}
        selectedLeadIds={selectedLeads}
        action="archive"
        endpoint="/lead-pool/bulk-archive"
      />
    </AppLayout>
  );
}
