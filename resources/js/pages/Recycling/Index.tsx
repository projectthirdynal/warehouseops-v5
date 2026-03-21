import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
  Recycle,
  Users,
  Clock,
  TrendingUp,
  Filter,
  Search,
  ArrowRight,
  UserPlus,
  MoreHorizontal,
  Eye,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { Lead, User } from '@/types';

interface RecycledLead extends Lead {
  days_in_pool: number;
  previous_agent?: User;
  recycle_reason?: string;
}

interface Props {
  leads: RecycledLead[];
  agents: User[];
  stats: {
    pool_size: number;
    recycled_today: number;
    avg_days_in_pool: number;
    reassigned_today: number;
  };
}

export default function RecyclingIndex({ leads, agents, stats }: Props) {
  const [selectedLeads, setSelectedLeads] = useState<number[]>([]);
  const [filterReason, setFilterReason] = useState('all');

  const toggleSelect = (id: number) => {
    setSelectedLeads(prev =>
      prev.includes(id) ? prev.filter(i => i !== id) : [...prev, id]
    );
  };

  const selectAll = () => {
    if (selectedLeads.length === leads?.length) {
      setSelectedLeads([]);
    } else {
      setSelectedLeads(leads?.map(l => l.id) || []);
    }
  };

  const handleBulkAssign = (agentId: number) => {
    // In production: POST /api/leads/bulk-assign
    alert(`Assigning ${selectedLeads.length} leads to agent ${agentId}`);
    setSelectedLeads([]);
  };

  return (
    <AppLayout>
      <Head title="Recycling Pool" />

      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Recycling Pool</h1>
            <p className="text-muted-foreground">
              Manage unassigned and recycled leads for redistribution
            </p>
          </div>
          <div className="flex gap-2">
            {selectedLeads.length > 0 && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button>
                    <UserPlus className="mr-2 h-4 w-4" />
                    Assign ({selectedLeads.length})
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  {agents?.map(agent => (
                    <DropdownMenuItem key={agent.id} onClick={() => handleBulkAssign(agent.id)}>
                      {agent.name}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            )}
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Recycle className="h-4 w-4" /> Pool Size
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.pool_size || leads?.length || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Clock className="h-4 w-4" /> Recycled Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">{stats?.recycled_today || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <TrendingUp className="h-4 w-4" /> Avg Days in Pool
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{stats?.avg_days_in_pool || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Users className="h-4 w-4" /> Reassigned Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">{stats?.reassigned_today || 0}</div>
            </CardContent>
          </Card>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col gap-4 md:flex-row">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <input
                  type="text"
                  placeholder="Search leads in pool..."
                  className="flex h-10 w-full rounded-md border border-input bg-background pl-10 pr-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <Select value={filterReason} onValueChange={setFilterReason}>
                <SelectTrigger className="w-full md:w-[200px]">
                  <SelectValue placeholder="Recycle Reason" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Reasons</SelectItem>
                  <SelectItem value="no_answer">No Answer (3x)</SelectItem>
                  <SelectItem value="callback_expired">Callback Expired</SelectItem>
                  <SelectItem value="agent_inactive">Agent Inactive</SelectItem>
                  <SelectItem value="manual">Manual Recycle</SelectItem>
                </SelectContent>
              </Select>
              <Button variant="outline">
                <Filter className="mr-2 h-4 w-4" />
                Filter
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Leads Table */}
        <Card>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="h-12 px-4 text-left align-middle">
                      <input
                        type="checkbox"
                        checked={selectedLeads.length === leads?.length && leads?.length > 0}
                        onChange={selectAll}
                        className="h-4 w-4 rounded border-gray-300"
                      />
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Lead</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Contact</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Recycle Reason</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Previous Agent</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Days in Pool</th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Cycles</th>
                    <th className="h-12 px-4 text-right align-middle font-medium text-muted-foreground">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {leads?.length > 0 ? (
                    leads.map((lead) => (
                      <tr key={lead.id} className="border-b transition-colors hover:bg-muted/50">
                        <td className="p-4 align-middle">
                          <input
                            type="checkbox"
                            checked={selectedLeads.includes(lead.id)}
                            onChange={() => toggleSelect(lead.id)}
                            className="h-4 w-4 rounded border-gray-300"
                          />
                        </td>
                        <td className="p-4 align-middle">
                          <div className="font-medium">{lead.name}</div>
                          <div className="text-sm text-muted-foreground">{lead.product_name}</div>
                        </td>
                        <td className="p-4 align-middle">
                          <div className="font-mono text-sm">{lead.phone}</div>
                          <div className="text-sm text-muted-foreground">{lead.city}</div>
                        </td>
                        <td className="p-4 align-middle">
                          <Badge variant="secondary">{lead.recycle_reason || 'Unknown'}</Badge>
                        </td>
                        <td className="p-4 align-middle text-sm">
                          {lead.previous_agent?.name || '-'}
                        </td>
                        <td className="p-4 align-middle">
                          <Badge variant={lead.days_in_pool > 7 ? 'destructive' : 'outline'}>
                            {lead.days_in_pool} days
                          </Badge>
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
                              <DropdownMenuItem>
                                <Eye className="mr-2 h-4 w-4" />
                                View Details
                              </DropdownMenuItem>
                              <DropdownMenuItem>
                                <UserPlus className="mr-2 h-4 w-4" />
                                Assign to Agent
                              </DropdownMenuItem>
                              <DropdownMenuItem>
                                <ArrowRight className="mr-2 h-4 w-4" />
                                Move to QC
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={8} className="h-24 text-center text-muted-foreground">
                        No leads in recycling pool
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
