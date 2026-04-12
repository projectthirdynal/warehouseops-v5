import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
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
import type { PaginatedResponse, PoolStats } from '@/types';
import { formatDate } from '@/lib/utils';

interface PoolLead {
  id: number;
  name: string;
  phone: string;
  city: string | null;
  state: string | null;
  product_name: string | null;
  pool_status: 'AVAILABLE' | 'ASSIGNED' | 'COOLDOWN' | 'EXHAUSTED';
  total_cycles: number;
  cooldown_until: string | null;
  created_at: string;
}

interface Agent {
  id: number;
  name: string;
  active_leads: number;
  max_active_cycles: number;
}

interface Props {
  leads: PaginatedResponse<PoolLead>;
  stats: PoolStats;
  agents: Agent[];
  filters: {
    pool_status?: string;
    search?: string;
  };
}

const poolStatusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
  AVAILABLE: { label: 'Available', variant: 'default' },
  ASSIGNED: { label: 'Assigned', variant: 'secondary' },
  COOLDOWN: { label: 'Cooldown', variant: 'outline' },
  EXHAUSTED: { label: 'Exhausted', variant: 'destructive' },
};

export default function LeadPoolIndex({ leads, stats, agents, filters }: Props) {
  const [search, setSearch] = useState(filters?.search || '');
  const [statusFilter, setStatusFilter] = useState(filters?.pool_status || 'all');
  const [selectedLeads, setSelectedLeads] = useState<number[]>([]);
  const [isDistributeOpen, setIsDistributeOpen] = useState(false);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.get('/lead-pool', {
      search,
      pool_status: statusFilter !== 'all' ? statusFilter : undefined,
    }, { preserveState: true });
  };

  const toggleLead = (leadId: number) => {
    setSelectedLeads((prev) =>
      prev.includes(leadId)
        ? prev.filter((id) => id !== leadId)
        : [...prev, leadId]
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
    router.get('/lead-pool', {
      page,
      search: filters?.search,
      pool_status: filters?.pool_status,
    }, { preserveState: true });
  };

  return (
    <AppLayout>
      <Head title="Lead Pool" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Lead Pool</h1>
            <p className="text-muted-foreground">
              Manage and distribute leads to agents
            </p>
          </div>
          {selectedLeads.length > 0 && (
            <Button onClick={() => setIsDistributeOpen(true)}>
              <UserPlus className="mr-2 h-4 w-4" />
              Distribute {selectedLeads.length} Leads
            </Button>
          )}
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Available</CardTitle>
              <Users className="h-4 w-4 text-green-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.available || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Assigned</CardTitle>
              <Clock className="h-4 w-4 text-blue-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats?.assigned || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Cooldown</CardTitle>
              <Pause className="h-4 w-4 text-yellow-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">{stats?.cooldown || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Exhausted</CardTitle>
              <AlertTriangle className="h-4 w-4 text-red-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-red-600">{stats?.exhausted || 0}</div>
            </CardContent>
          </Card>
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
              <Button type="submit">
                <Filter className="mr-2 h-4 w-4" />
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
                    <th className="h-12 px-4 text-left align-middle">
                      <Checkbox
                        checked={
                          leads?.data?.length > 0 &&
                          selectedLeads.length === leads.data.length
                        }
                        onCheckedChange={toggleAll}
                      />
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      <button className="flex items-center gap-1 hover:text-foreground">
                        Name <ArrowUpDown className="h-4 w-4" />
                      </button>
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Phone</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Location</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Product</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Pool Status</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Cycles</th>
                  </tr>
                </thead>
                <tbody>
                  {leads?.data?.length > 0 ? (
                    leads.data.map((lead) => {
                      const statusCfg = poolStatusConfig[lead.pool_status];
                      return (
                        <tr
                          key={lead.id}
                          className="border-b transition-colors hover:bg-muted/50"
                        >
                          <td className="p-4 align-middle">
                            <Checkbox
                              checked={selectedLeads.includes(lead.id)}
                              onCheckedChange={() => toggleLead(lead.id)}
                            />
                          </td>
                          <td className="p-4 align-middle">
                            <div className="font-medium">{lead.name}</div>
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
                          </td>
                          <td className="p-4 align-middle">
                            <Badge variant={statusCfg?.variant || 'secondary'}>
                              {statusCfg?.label || lead.pool_status}
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
                      <td colSpan={7} className="h-24 text-center text-muted-foreground">
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
      <DistributionModal
        isOpen={isDistributeOpen}
        onClose={() => setIsDistributeOpen(false)}
        selectedLeadIds={selectedLeads}
        agents={agents}
      />
    </AppLayout>
  );
}
