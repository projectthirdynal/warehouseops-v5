import { Head } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
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
  Zap,
  Settings2,
  Save,
  Trash2,
  Loader2,
  AlertCircle,
  RefreshCw,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
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
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import type { Lead, User } from '@/types';

interface RecyclingRule {
  id: number;
  outcome: string;
  cooldown_hours: number;
  max_cycles: number;
  next_action: string;
  is_active: boolean;
}

interface RecyclingStats {
  pool_size: number;
  recycled_today: number;
  avg_days_in_pool: number;
  reassigned_today: number;
  cooldown_count: number;
  cooldown_expired: number;
  exhausted_count: number;
  available_count: number;
  expired_callbacks: number;
  rules_count: number;
  outcome_breakdown: Record<string, number>;
}

interface RecycledLead extends Lead {
  days_in_pool: number;
  previous_agent?: User;
  recycle_reason?: string;
}

interface Props {
  leads: RecycledLead[];
  agents: User[];
  stats: RecyclingStats;
  rules: RecyclingRule[];
}

const OUTCOME_LABELS: Record<string, string> = {
  NO_ANSWER: 'No Answer',
  CALLBACK: 'Callback',
  INTERESTED: 'Interested',
  ORDERED: 'Ordered/Sold',
  NOT_INTERESTED: 'Not Interested',
  WRONG_NUMBER: 'Wrong Number',
};

export default function RecyclingIndex({
  leads,
  agents,
  stats: initialStats,
  rules: initialRules,
}: Props) {
  const [selectedLeads, setSelectedLeads] = useState<number[]>([]);
  const [filterReason, setFilterReason] = useState('all');
  const [stats, setStats] = useState<RecyclingStats>(initialStats);
  const [rules, setRules] = useState<RecyclingRule[]>(initialRules);
  const [triggering, setTriggering] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [editingRule, setEditingRule] = useState<RecyclingRule | null>(null);
  const [showRuleDialog, setShowRuleDialog] = useState(false);
  const [savingRule, setSavingRule] = useState(false);

  const refreshStats = useCallback(async () => {
    setRefreshing(true);
    try {
      const { data } = await axios.get('/recycling/stats');
      setStats(data);
    } catch {
      toast.error('Failed to refresh stats');
    } finally {
      setRefreshing(false);
    }
  }, []);

  const refreshRules = useCallback(async () => {
    try {
      const { data } = await axios.get('/recycling/rules');
      setRules(data);
    } catch {
      toast.error('Failed to load rules');
    }
  }, []);

  const handleTrigger = async () => {
    setTriggering(true);
    try {
      const { data } = await axios.post('/recycling/trigger', { type: 'all' });
      toast.success(
        `Processed ${data.total_processed} leads (cooldowns: ${data.cooldown_processed}, callbacks: ${data.callbacks_processed})`
      );
      refreshStats();
    } catch {
      toast.error('Failed to trigger recycling');
    } finally {
      setTriggering(false);
    }
  };

  const handleEditRule = (rule: RecyclingRule) => {
    setEditingRule(rule);
    setShowRuleDialog(true);
  };

  const handleSaveRule = async () => {
    if (!editingRule) return;
    setSavingRule(true);
    try {
      await axios.patch(`/recycling/rules/${editingRule.id}`, {
        cooldown_hours: editingRule.cooldown_hours,
        max_cycles: editingRule.max_cycles,
        next_action: editingRule.next_action,
        is_active: editingRule.is_active,
      });
      toast.success(
        `Rule for ${OUTCOME_LABELS[editingRule.outcome] || editingRule.outcome} updated`
      );
      setShowRuleDialog(false);
      refreshRules();
      refreshStats();
    } catch {
      toast.error('Failed to update rule');
    } finally {
      setSavingRule(false);
    }
  };

  const handleDeleteRule = async (rule: RecyclingRule) => {
    if (!confirm(`Delete rule for ${OUTCOME_LABELS[rule.outcome] || rule.outcome}?`)) return;
    try {
      await axios.delete(`/recycling/rules/${rule.id}`);
      toast.success('Rule deleted');
      refreshRules();
      refreshStats();
    } catch {
      toast.error('Failed to delete rule');
    }
  };

  const toggleSelect = (id: number) => {
    setSelectedLeads((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]));
  };

  const selectAll = () => {
    if (selectedLeads.length === leads?.length) {
      setSelectedLeads([]);
    } else {
      setSelectedLeads(leads?.map((l) => l.id) || []);
    }
  };

  const handleBulkAssign = (agentId: number) => {
    alert(`Assigning ${selectedLeads.length} leads to agent ${agentId}`);
    setSelectedLeads([]);
  };

  return (
    <AppLayout>
      <Head title="Recycling Automation" />

      <div className="space-y-4">
        {/* Header */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Recycling Automation</h1>
            <p className="text-muted-foreground">
              Manage lead recycling rules, monitor pool health, and trigger processing
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={refreshStats} disabled={refreshing}>
              {refreshing ? (
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
              ) : (
                <RefreshCw className="mr-1.5 h-4 w-4" />
              )}
              Refresh
            </Button>
            <Button onClick={handleTrigger} disabled={triggering}>
              {triggering ? (
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
              ) : (
                <Zap className="mr-1.5 h-4 w-4" />
              )}
              Trigger Now
            </Button>
            {selectedLeads.length > 0 && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button>
                    <UserPlus className="mr-1.5 h-4 w-4" />
                    Assign ({selectedLeads.length})
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  {agents?.map((agent) => (
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
        <div className="grid gap-4 md:grid-cols-4 lg:grid-cols-6">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Recycle className="h-4 w-4" /> Pool Size
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats?.pool_size || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Clock className="h-4 w-4" /> Recycled Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-warning">
                {stats?.recycled_today || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <TrendingUp className="h-4 w-4" /> Avg Days in Pool
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display">{stats?.avg_days_in_pool || 0}</div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Users className="h-4 w-4" /> Reassigned Today
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-success">
                {stats?.reassigned_today || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <AlertCircle className="h-4 w-4" /> Cooldown Expired
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {stats?.cooldown_expired || 0}
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <AlertCircle className="h-4 w-4" /> Expired Callbacks
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-xl font-bold font-display text-destructive">
                {stats?.expired_callbacks || 0}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Pool Status Breakdown */}
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Available</span>
                <Badge variant="success">{stats?.available_count || 0}</Badge>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">In Cooldown</span>
                <Badge variant="warning">{stats?.cooldown_count || 0}</Badge>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Exhausted</span>
                <Badge variant="destructive">{stats?.exhausted_count || 0}</Badge>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-6">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Active Rules</span>
                <Badge variant="info">{stats?.rules_count || 0}</Badge>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Recycling Rules */}
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <Settings2 className="h-5 w-5" /> Recycling Rules
                </CardTitle>
                <CardDescription>
                  Configure cooldown, max cycles, and action per outcome
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="h-10 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                      Outcome
                    </th>
                    <th className="h-10 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                      Cooldown (hrs)
                    </th>
                    <th className="h-10 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                      Max Cycles
                    </th>
                    <th className="h-10 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                      Action
                    </th>
                    <th className="h-10 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                      Active
                    </th>
                    <th className="h-10 px-4 text-right align-middle font-medium text-muted-foreground text-sm">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rules?.length > 0 ? (
                    rules.map((rule) => (
                      <tr key={rule.id} className="border-b transition-colors hover:bg-muted/50">
                        <td className="p-4 align-middle">
                          <Badge variant="secondary">
                            {OUTCOME_LABELS[rule.outcome] || rule.outcome}
                          </Badge>
                        </td>
                        <td className="p-4 align-middle text-sm">{rule.cooldown_hours}</td>
                        <td className="p-4 align-middle text-sm">{rule.max_cycles}</td>
                        <td className="p-4 align-middle">
                          <Badge
                            variant={rule.next_action === 'EXHAUST' ? 'destructive' : 'outline'}
                          >
                            {rule.next_action}
                          </Badge>
                        </td>
                        <td className="p-4 align-middle">
                          <Switch checked={rule.is_active} disabled />
                        </td>
                        <td className="p-4 align-middle text-right">
                          <div className="flex justify-end gap-1">
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => handleEditRule(rule)}
                            >
                              <Settings2 className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              onClick={() => handleDeleteRule(rule)}
                            >
                              <Trash2 className="h-4 w-4 text-destructive" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={6} className="h-24 text-center text-muted-foreground">
                        No recycling rules configured
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>

        {/* Outcome Breakdown */}
        {stats?.outcome_breakdown && Object.keys(stats.outcome_breakdown).length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Outcome Breakdown</CardTitle>
              <CardDescription>Closed cycles grouped by outcome</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="flex flex-wrap gap-3">
                {Object.entries(stats.outcome_breakdown).map(([outcome, count]) => (
                  <div
                    key={outcome}
                    className="flex items-center gap-2 rounded-lg border px-3 py-2"
                  >
                    <Badge variant="secondary">{OUTCOME_LABELS[outcome] || outcome}</Badge>
                    <span className="text-sm font-bold">{count}</span>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        )}

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
                  <SelectItem value="no_answer">No Answer</SelectItem>
                  <SelectItem value="callback_expired">Callback Expired</SelectItem>
                  <SelectItem value="agent_inactive">Agent Inactive</SelectItem>
                  <SelectItem value="manual">Manual Recycle</SelectItem>
                </SelectContent>
              </Select>
              <Button variant="outline">
                <Filter className="mr-1.5 h-4 w-4" />
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
                        className="h-4 w-4 rounded border-border"
                      />
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Lead
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Contact
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Recycle Reason
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Previous Agent
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Days in Pool
                    </th>
                    <th className="h-12 px-4 text-left align-middle font-medium text-muted-foreground">
                      Cycles
                    </th>
                    <th className="h-12 px-4 text-right align-middle font-medium text-muted-foreground">
                      Actions
                    </th>
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
                            className="h-4 w-4 rounded border-border"
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
                                <Eye className="mr-1.5 h-4 w-4" />
                                View Details
                              </DropdownMenuItem>
                              <DropdownMenuItem>
                                <UserPlus className="mr-1.5 h-4 w-4" />
                                Assign to Agent
                              </DropdownMenuItem>
                              <DropdownMenuItem>
                                <ArrowRight className="mr-1.5 h-4 w-4" />
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

      {/* Edit Rule Dialog */}
      <Dialog open={showRuleDialog} onOpenChange={setShowRuleDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              Edit Rule:{' '}
              {editingRule ? OUTCOME_LABELS[editingRule.outcome] || editingRule.outcome : ''}
            </DialogTitle>
            <DialogDescription>
              Adjust cooldown hours, max cycles, and action for this outcome
            </DialogDescription>
          </DialogHeader>
          {editingRule && (
            <div className="space-y-4 py-2">
              <div className="space-y-2">
                <Label htmlFor="cooldown_hours">Cooldown Hours</Label>
                <Input
                  id="cooldown_hours"
                  type="number"
                  min={0}
                  max={1440}
                  value={editingRule.cooldown_hours}
                  onChange={(e) =>
                    setEditingRule({
                      ...editingRule,
                      cooldown_hours: parseInt(e.target.value) || 0,
                    })
                  }
                />
                <p className="text-xs text-muted-foreground">
                  Hours before lead becomes available again (0 = immediate)
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="max_cycles">Max Cycles</Label>
                <Input
                  id="max_cycles"
                  type="number"
                  min={1}
                  max={999}
                  value={editingRule.max_cycles}
                  onChange={(e) =>
                    setEditingRule({ ...editingRule, max_cycles: parseInt(e.target.value) || 1 })
                  }
                />
                <p className="text-xs text-muted-foreground">
                  Maximum recycle attempts before exhausting the lead
                </p>
              </div>
              <div className="space-y-2">
                <Label htmlFor="next_action">Next Action</Label>
                <Select
                  value={editingRule.next_action}
                  onValueChange={(value) => setEditingRule({ ...editingRule, next_action: value })}
                >
                  <SelectTrigger id="next_action">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="RECYCLE">Recycle (return to pool)</SelectItem>
                    <SelectItem value="EXHAUST">Exhaust (remove from pool)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-center justify-between">
                <Label htmlFor="is_active">Active</Label>
                <Switch
                  id="is_active"
                  checked={editingRule.is_active}
                  onCheckedChange={(checked) =>
                    setEditingRule({ ...editingRule, is_active: checked })
                  }
                />
              </div>
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowRuleDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleSaveRule} disabled={savingRule}>
              {savingRule ? (
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
              ) : (
                <Save className="mr-1.5 h-4 w-4" />
              )}
              Save Changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
