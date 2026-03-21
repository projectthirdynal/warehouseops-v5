import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Search,
  Filter,
  Plus,
  MoreHorizontal,
  Eye,
  Phone,
  UserPlus,
  RefreshCw,
  ArrowUpDown,
  Users,
  TrendingUp,
  Clock,
  CheckCircle,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { Lead, PaginatedResponse, LeadStatus, SalesStatus } from '@/types';

interface Props {
  leads: PaginatedResponse<Lead>;
  filters: {
    status?: string;
    sales_status?: string;
    search?: string;
    assigned?: string;
  };
  stats: {
    total: number;
    new: number;
    in_progress: number;
    converted: number;
    conversion_rate: number;
  };
}

const statusConfig: Record<LeadStatus, { label: string; color: string }> = {
  NEW: { label: 'New', color: 'bg-blue-100 text-blue-800' },
  CALLING: { label: 'Calling', color: 'bg-yellow-100 text-yellow-800' },
  NO_ANSWER: { label: 'No Answer', color: 'bg-gray-100 text-gray-800' },
  REJECT: { label: 'Rejected', color: 'bg-red-100 text-red-800' },
  CALLBACK: { label: 'Callback', color: 'bg-purple-100 text-purple-800' },
  SALE: { label: 'Sale', color: 'bg-green-100 text-green-800' },
  REORDER: { label: 'Reorder', color: 'bg-emerald-100 text-emerald-800' },
  DELIVERED: { label: 'Delivered', color: 'bg-green-100 text-green-800' },
  RETURNED: { label: 'Returned', color: 'bg-red-100 text-red-800' },
  CANCELLED: { label: 'Cancelled', color: 'bg-gray-100 text-gray-800' },
  ARCHIVED: { label: 'Archived', color: 'bg-gray-100 text-gray-800' },
};

const salesStatusConfig: Record<SalesStatus, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
  NEW: { label: 'New', variant: 'secondary' },
  CONTACTED: { label: 'Contacted', variant: 'outline' },
  AGENT_CONFIRMED: { label: 'Agent Confirmed', variant: 'default' },
  QA_PENDING: { label: 'QA Pending', variant: 'secondary' },
  QA_APPROVED: { label: 'QA Approved', variant: 'default' },
  QA_REJECTED: { label: 'QA Rejected', variant: 'destructive' },
  OPS_APPROVED: { label: 'Ops Approved', variant: 'default' },
  CANCELLED: { label: 'Cancelled', variant: 'destructive' },
  WAYBILL_CREATED: { label: 'Waybill Created', variant: 'default' },
};

export default function LeadsIndex({ leads, filters, stats }: Props) {
  const [search, setSearch] = useState(filters?.search || '');
  const [statusFilter, setStatusFilter] = useState(filters?.status || 'all');

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    router.get('/leads', { search, status: statusFilter !== 'all' ? statusFilter : undefined }, { preserveState: true });
  };

  return (
    <AppLayout>
      <Head title="Leads" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Leads</h1>
            <p className="text-muted-foreground">
              Manage customer leads and track conversions
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm">
              <RefreshCw className="mr-2 h-4 w-4" />
              Sync
            </Button>
            <Button size="sm">
              <Plus className="mr-2 h-4 w-4" />
              Add Lead
            </Button>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Total Leads</CardTitle>
              <Users className="h-4 w-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.total || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">New Leads</CardTitle>
              <Clock className="h-4 w-4 text-blue-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">{stats?.new || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Converted</CardTitle>
              <CheckCircle className="h-4 w-4 text-green-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.converted || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="flex flex-row items-center justify-between pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground">Conversion Rate</CardTitle>
              <TrendingUp className="h-4 w-4 text-emerald-600" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-emerald-600">{stats?.conversion_rate || 0}%</div>
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
                  placeholder="Search by name, phone, or address..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background pl-10 pr-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="w-full md:w-[180px]">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="NEW">New</SelectItem>
                  <SelectItem value="CALLING">Calling</SelectItem>
                  <SelectItem value="CALLBACK">Callback</SelectItem>
                  <SelectItem value="SALE">Sale</SelectItem>
                  <SelectItem value="NO_ANSWER">No Answer</SelectItem>
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
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      <button className="flex items-center gap-1 hover:text-foreground">
                        Name <ArrowUpDown className="h-4 w-4" />
                      </button>
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Contact</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Product</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Status</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Sales Status</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Agent</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Cycles</th>
                    <th className="h-12 px-4 text-right align-middle font-medium text-muted-foreground">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {leads?.data?.length > 0 ? (
                    leads.data.map((lead) => {
                      const statusCfg = statusConfig[lead.status];
                      const salesCfg = salesStatusConfig[lead.sales_status];
                      return (
                        <tr key={lead.id} className="border-b transition-colors hover:bg-muted/50">
                          <td className="p-4 align-middle">
                            <div className="font-medium">{lead.name}</div>
                            <div className="text-sm text-muted-foreground truncate max-w-[200px]">
                              {lead.address}
                            </div>
                          </td>
                          <td className="p-4 align-middle">
                            <div className="flex items-center gap-2">
                              <Phone className="h-4 w-4 text-muted-foreground" />
                              <span className="font-mono text-sm">{lead.phone}</span>
                            </div>
                          </td>
                          <td className="p-4 align-middle">
                            <div className="text-sm">{lead.product_name || '-'}</div>
                            {lead.amount && (
                              <div className="text-sm font-medium text-green-600">
                                ₱{lead.amount.toLocaleString()}
                              </div>
                            )}
                          </td>
                          <td className="p-4 align-middle">
                            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${statusCfg?.color || 'bg-gray-100 text-gray-800'}`}>
                              {statusCfg?.label || lead.status}
                            </span>
                          </td>
                          <td className="p-4 align-middle">
                            <Badge variant={salesCfg?.variant || 'secondary'}>
                              {salesCfg?.label || lead.sales_status}
                            </Badge>
                          </td>
                          <td className="p-4 align-middle text-sm">
                            {lead.assigned_agent?.name || (
                              <span className="text-muted-foreground">Unassigned</span>
                            )}
                          </td>
                          <td className="p-4 align-middle">
                            <Badge variant="outline">{lead.total_cycles}</Badge>
                          </td>
                          <td className="p-4 align-middle text-right">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon">
                                  <MoreHorizontal className="h-4 w-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                <DropdownMenuItem onClick={() => router.visit(`/leads/${lead.id}`)}>
                                  <Eye className="mr-2 h-4 w-4" />
                                  View Details
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                  <Phone className="mr-2 h-4 w-4" />
                                  Call
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem>
                                  <UserPlus className="mr-2 h-4 w-4" />
                                  Assign Agent
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                  <RefreshCw className="mr-2 h-4 w-4" />
                                  Recycle
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </td>
                        </tr>
                      );
                    })
                  ) : (
                    <tr>
                      <td colSpan={8} className="h-24 text-center text-muted-foreground">
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
                  >
                    Previous
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={leads.current_page === leads.last_page}
                  >
                    Next
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
