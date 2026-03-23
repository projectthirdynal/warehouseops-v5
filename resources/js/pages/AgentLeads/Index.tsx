import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Search,
  Filter,
  RefreshCw,
  Phone,
  Users,
  Clock,
  TrendingUp,
  CheckCircle,
  XCircle,
  AlertCircle,
  Package,
  Recycle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { LeadCard } from './LeadCard';
import type { AgentLead, PoolStats } from '@/types/lead-pool';

interface Props {
  leads: AgentLead[];
  stats: {
    assigned: number;
    called_today: number;
    sold_today: number;
    callbacks_due: number;
    conversion_rate: number;
  };
  poolStats: PoolStats;
  filters: {
    status?: string;
    search?: string;
    product?: string;
  };
  callbacksToday: AgentLead[];
  productSkills: string[];
  matchingInPool: Record<string, number>;
}

export default function AgentLeadsIndex({
  leads,
  stats,
  poolStats,
  filters,
  callbacksToday,
  productSkills,
  matchingInPool,
}: Props) {
  const [search, setSearch] = useState(filters?.search || '');
  const [statusFilter, setStatusFilter] = useState(filters?.status || 'all');
  const [isRefreshing, setIsRefreshing] = useState(false);
  const [requestingProduct, setRequestingProduct] = useState<string | null>(null);
  const [toast, setToast] = useState<{ message: string; type: 'success' | 'error' } | null>(null);

  const showToast = (message: string, type: 'success' | 'error') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 4000);
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.get(
      '/agent/leads',
      { search, status: statusFilter !== 'all' ? statusFilter : undefined },
      { preserveState: true }
    );
  };

  const handleRefresh = () => {
    setIsRefreshing(true);
    router.reload({ onFinish: () => setIsRefreshing(false) });
  };

  const requestLeads = async (product?: string) => {
    setRequestingProduct(product ?? 'any');
    try {
      const response = await fetch('/api/agent/leads/request', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN':
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ count: 5, product: product ?? null }),
      });

      const data = await response.json();

      if (!response.ok && response.status === 422) {
        showToast(data.message, 'error');
        return;
      }

      showToast(data.message, data.assigned > 0 ? 'success' : 'error');
      if (data.assigned > 0) router.reload();
    } catch {
      showToast('Failed to request leads. Please try again.', 'error');
    } finally {
      setRequestingProduct(null);
    }
  };

  return (
    <AppLayout>
      <Head title="My Leads" />

      {/* Toast */}
      {toast && (
        <div
          className={`fixed top-4 right-4 z-50 max-w-sm rounded-lg px-4 py-3 text-sm shadow-lg transition-all ${
            toast.type === 'success'
              ? 'bg-green-600 text-white'
              : 'bg-red-600 text-white'
          }`}
        >
          {toast.message}
        </div>
      )}

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">My Leads</h1>
            <p className="text-muted-foreground">
              Manage your assigned leads and track your performance
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={handleRefresh} disabled={isRefreshing}>
              <RefreshCw className={`mr-2 h-4 w-4 ${isRefreshing ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            <Button size="sm" onClick={() => requestLeads()} disabled={requestingProduct !== null}>
              <Users className="mr-2 h-4 w-4" />
              {requestingProduct === 'any' ? 'Requesting...' : 'Request Leads'}
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-5">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Assigned</CardTitle>
              <Users className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.assigned || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Called Today</CardTitle>
              <Phone className="h-4 w-4 text-blue-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats?.called_today || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Sold Today</CardTitle>
              <CheckCircle className="h-4 w-4 text-green-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.sold_today || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Callbacks Due</CardTitle>
              <Clock className="h-4 w-4 text-yellow-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">{stats?.callbacks_due || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Conversion</CardTitle>
              <TrendingUp className="h-4 w-4 text-emerald-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-emerald-600">{stats?.conversion_rate || 0}%</div>
            </CardContent>
          </Card>
        </div>

        {/* Product Skills — quick request per product */}
        {productSkills && productSkills.length > 0 && (
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-sm font-medium">
                <Package className="h-4 w-4" />
                My Product Lines
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap gap-3">
                {productSkills.map((skill) => {
                  const count = matchingInPool?.[skill] ?? 0;
                  return (
                    <div
                      key={skill}
                      className="flex items-center gap-2 rounded-lg border px-3 py-2 bg-muted/30"
                    >
                      <div>
                        <span className="text-sm font-medium">{skill}</span>
                        <span className="ml-2 text-xs text-muted-foreground">
                          {count} in pool
                        </span>
                      </div>
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-7 px-2 text-xs"
                        disabled={count === 0 || requestingProduct !== null}
                        onClick={() => requestLeads(skill)}
                      >
                        {requestingProduct === skill ? (
                          <RefreshCw className="h-3 w-3 animate-spin" />
                        ) : (
                          <>
                            <Recycle className="mr-1 h-3 w-3" />
                            Pull
                          </>
                        )}
                      </Button>
                    </div>
                  );
                })}
              </div>
              {Object.values(matchingInPool ?? {}).every((c) => c === 0) && (
                <p className="mt-2 text-xs text-muted-foreground">
                  No matching product leads in pool right now. You can still request general leads above.
                </p>
              )}
            </CardContent>
          </Card>
        )}

        {/* Callbacks Alert */}
        {callbacksToday && callbacksToday.length > 0 && (
          <Card className="border-yellow-200 bg-yellow-50">
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-yellow-800">
                <AlertCircle className="h-5 w-5" />
                Callbacks Due Today ({callbacksToday.length})
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap gap-2">
                {callbacksToday.map((lead) => (
                  <Badge
                    key={lead.id}
                    variant="outline"
                    className="cursor-pointer hover:bg-yellow-100"
                    onClick={() => {
                      document.getElementById(`lead-${lead.id}`)?.scrollIntoView({ behavior: 'smooth' });
                    }}
                  >
                    {lead.name}
                  </Badge>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

        {/* Pool Status */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium">Lead Pool Status</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-4 text-sm">
              <div className="flex items-center gap-2">
                <div className="h-3 w-3 rounded-full bg-green-500" />
                <span>Available: {poolStats?.available || 0}</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="h-3 w-3 rounded-full bg-blue-500" />
                <span>Assigned: {poolStats?.assigned || 0}</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="h-3 w-3 rounded-full bg-yellow-500" />
                <span>Cooldown: {poolStats?.cooldown || 0}</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="h-3 w-3 rounded-full bg-red-500" />
                <span>Exhausted: {poolStats?.exhausted || 0}</span>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <form onSubmit={handleSearch} className="flex flex-col gap-4 md:flex-row">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="text"
                  placeholder="Search by name or location..."
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
                  <SelectItem value="ASSIGNED">Assigned</SelectItem>
                  <SelectItem value="COOLDOWN">Cooldown</SelectItem>
                </SelectContent>
              </Select>
              <Button type="submit">
                <Filter className="mr-2 h-4 w-4" />
                Filter
              </Button>
            </form>
          </CardContent>
        </Card>

        {/* Leads List */}
        <div className="space-y-4">
          {leads && leads.length > 0 ? (
            leads.map((lead) => (
              <div key={lead.id} id={`lead-${lead.id}`}>
                <LeadCard lead={lead} onUpdate={handleRefresh} />
              </div>
            ))
          ) : (
            <Card>
              <CardContent className="py-12 text-center">
                <XCircle className="mx-auto h-12 w-12 text-muted-foreground" />
                <h3 className="mt-4 text-lg font-medium">No leads assigned</h3>
                <p className="mt-2 text-sm text-muted-foreground">
                  {productSkills?.length > 0
                    ? `Pull leads from your product lines above, or request any available lead.`
                    : 'Request new leads to get started.'}
                </p>
                <Button className="mt-4" onClick={() => requestLeads()} disabled={requestingProduct !== null}>
                  <Users className="mr-2 h-4 w-4" />
                  Request Leads
                </Button>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
