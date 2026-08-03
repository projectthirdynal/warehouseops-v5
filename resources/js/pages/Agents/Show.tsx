import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  ArrowLeft,
  Mail,
  Phone,
  TrendingUp,
  Target,
  DollarSign,
  Activity,
  Clock,
  Users,
  CheckCircle,
  Calendar,
  Save,
  Sunrise,
  Sunset,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { formatDate } from '@/lib/utils';
import type { User, AgentProfile } from '@/types';

interface Agent extends User {
  phone?: string;
  agentProfile?: AgentProfile;
  last_login_at?: string;
}

interface RecentLead {
  id: number;
  name: string;
  status: string;
  amount: number;
  created_at: string;
  updated_at: string;
}

interface Props {
  agent: Agent;
  stats: {
    total_leads: number;
    total_sales: number;
    active_leads: number;
    conversion_rate: number;
    total_revenue: number;
    leads_today: number;
    sales_today: number;
  };
  recentLeads: RecentLead[];
}

const statusColors: Record<string, string> = {
  NEW: 'text-info',
  CONTACTED: 'text-warning',
  FOLLOW_UP: 'text-warning',
  SALE: 'text-success',
  DELIVERED: 'text-success',
  RETURNED: 'text-destructive',
  CANCELLED: 'text-destructive',
  ARCHIVED: 'text-muted-foreground',
};

function getInitials(name: string) {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}

function isCurrentlyInShift(profile?: AgentProfile): boolean {
  if (!profile?.shift_start || !profile?.shift_end) return true;
  const now = new Date();
  const nowTime = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
  const start = profile.shift_start;
  const end = profile.shift_end;
  if (end < start) {
    return nowTime >= start || nowTime < end;
  }
  return nowTime >= start && nowTime < end;
}

export default function AgentShow({ agent, stats, recentLeads }: Props) {
  const profile = agent.agentProfile;
  const [editingShift, setEditingShift] = useState(false);
  const [shiftStart, setShiftStart] = useState(profile?.shift_start ?? '');
  const [shiftEnd, setShiftEnd] = useState(profile?.shift_end ?? '');
  const [savingShift, setSavingShift] = useState(false);

  const inShift = isCurrentlyInShift(profile);

  const handleSaveShift = () => {
    setSavingShift(true);
    router.patch(
      `/agents/${agent.id}/profile`,
      {
        shift_start: shiftStart || null,
        shift_end: shiftEnd || null,
      },
      {
        onFinish: () => {
          setSavingShift(false);
          setEditingShift(false);
        },
      }
    );
  };

  return (
    <AppLayout>
      <Head title={`Agent — ${agent.name}`} />

      <div className="space-y-6">
        {/* Back button */}
        <div>
          <Link href="/agents/governance">
            <Button variant="ghost" size="sm">
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Back to Agents
            </Button>
          </Link>
        </div>

        {/* Agent Header */}
        <Card>
          <CardContent className="pt-6">
            <div className="flex flex-col gap-6 md:flex-row md:items-center">
              <Avatar className="h-20 w-20">
                <AvatarFallback className="bg-primary text-primary-foreground text-2xl">
                  {getInitials(agent.name)}
                </AvatarFallback>
              </Avatar>

              <div className="flex-1">
                <div className="flex items-center gap-3">
                  <h1 className="text-xl font-bold font-display">{agent.name}</h1>
                  <Badge variant={agent.is_active ? 'default' : 'secondary'}>
                    {agent.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                  {profile?.is_available && (
                    <Badge variant="outline" className="text-success">
                      Available
                    </Badge>
                  )}
                </div>

                <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-muted-foreground">
                  <div className="flex items-center gap-1">
                    <Mail className="h-4 w-4" />
                    {agent.email}
                  </div>
                  {agent.phone && (
                    <div className="flex items-center gap-1">
                      <Phone className="h-4 w-4" />
                      {agent.phone}
                    </div>
                  )}
                  {agent.last_login_at && (
                    <div className="flex items-center gap-1">
                      <Clock className="h-4 w-4" />
                      Last login: {formatDate(agent.last_login_at)}
                    </div>
                  )}
                </div>
              </div>

              {/* Performance Score */}
              <div className="text-center">
                <div className="text-3xl font-bold font-display">
                  {profile?.performance_score ?? 50}%
                </div>
                <div className="text-xs text-muted-foreground">Performance</div>
                <div className="mt-2 h-2 w-32 rounded-full bg-muted">
                  <div
                    className={`h-full rounded-full ${
                      (profile?.performance_score ?? 50) >= 70
                        ? 'bg-success'
                        : (profile?.performance_score ?? 50) >= 40
                          ? 'bg-warning'
                          : 'bg-destructive'
                    }`}
                    style={{ width: `${profile?.performance_score ?? 50}%` }}
                  />
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Stats Grid */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <Target className="h-4 w-4" /> Total Leads
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display">{stats.total_leads}</div>
              <div className="text-xs text-muted-foreground mt-1">{stats.leads_today} today</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-success" /> Total Sales
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display text-success">
                {stats.total_sales}
              </div>
              <div className="text-xs text-muted-foreground mt-1">{stats.sales_today} today</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <TrendingUp className="h-4 w-4" /> Conversion Rate
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display">{stats.conversion_rate}%</div>
              <div className="text-xs text-muted-foreground mt-1">
                {stats.active_leads} active leads
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                <DollarSign className="h-4 w-4" /> Total Revenue
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold font-display">
                ₱{Number(stats.total_revenue || 0).toLocaleString()}
              </div>
              <div className="text-xs text-muted-foreground mt-1">From closed sales</div>
            </CardContent>
          </Card>
        </div>

        {/* Shift Schedule Card */}
        {profile && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Clock className="h-5 w-5" />
                Shift Schedule
                {profile.shift_start && profile.shift_end ? (
                  <Badge
                    variant={inShift ? 'default' : 'secondary'}
                    className={inShift ? 'text-success' : ''}
                  >
                    {inShift ? 'In Shift' : 'Off Shift'}
                  </Badge>
                ) : (
                  <Badge variant="outline">No shift set</Badge>
                )}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {!editingShift ? (
                <div className="space-y-3">
                  <div className="flex items-center gap-6">
                    <div className="flex items-center gap-2">
                      <Sunrise className="h-4 w-4 text-muted-foreground" />
                      <div>
                        <div className="text-xs text-muted-foreground">Start</div>
                        <div className="text-sm font-medium">{profile.shift_start ?? '—'}</div>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Sunset className="h-4 w-4 text-muted-foreground" />
                      <div>
                        <div className="text-xs text-muted-foreground">End</div>
                        <div className="text-sm font-medium">{profile.shift_end ?? '—'}</div>
                      </div>
                    </div>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Leads are only assigned during shift hours. The system auto-toggles availability
                    at shift boundaries. Leave blank for 24/7 availability.
                  </p>
                  <Button variant="outline" size="sm" onClick={() => setEditingShift(true)}>
                    Edit Shift Schedule
                  </Button>
                </div>
              ) : (
                <div className="space-y-4">
                  <div className="flex items-center gap-6">
                    <div className="space-y-1">
                      <label className="text-xs text-muted-foreground">Shift Start</label>
                      <input
                        type="time"
                        value={shiftStart}
                        onChange={(e) => setShiftStart(e.target.value)}
                        className="flex h-9 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      />
                    </div>
                    <div className="space-y-1">
                      <label className="text-xs text-muted-foreground">Shift End</label>
                      <input
                        type="time"
                        value={shiftEnd}
                        onChange={(e) => setShiftEnd(e.target.value)}
                        className="flex h-9 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      />
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" onClick={handleSaveShift} disabled={savingShift}>
                      <Save className="mr-1.5 h-3.5 w-3.5" />
                      {savingShift ? 'Saving...' : 'Save'}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setEditingShift(false);
                        setShiftStart(profile?.shift_start ?? '');
                        setShiftEnd(profile?.shift_end ?? '');
                      }}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {/* Agent Profile Details */}
        {profile && (
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Activity className="h-5 w-5" />
                  Distribution Settings
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Max Active Cycles</span>
                  <span className="font-medium">{profile.max_active_cycles}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Available</span>
                  <Badge variant={profile.is_available ? 'default' : 'secondary'}>
                    {profile.is_available ? 'Yes' : 'No'}
                  </Badge>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Auto-Assign</span>
                  <Badge variant={profile.auto_assign_enabled ? 'default' : 'secondary'}>
                    {profile.auto_assign_enabled ? 'Enabled' : 'Disabled'}
                  </Badge>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-muted-foreground">Priority Weight</span>
                  <span className="font-medium">{profile.priority_weight}</span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Users className="h-5 w-5" />
                  Skills & Regions
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <div className="text-sm font-medium mb-2">Product Skills</div>
                  {profile.product_skills?.length > 0 ? (
                    <div className="flex flex-wrap gap-1.5">
                      {profile.product_skills.map((skill, i) => (
                        <Badge key={i} variant="outline">
                          {skill}
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-muted-foreground">No product skills set</p>
                  )}
                </div>

                <div>
                  <div className="text-sm font-medium mb-2">Regions</div>
                  {profile.regions?.length > 0 ? (
                    <div className="flex flex-wrap gap-1.5">
                      {profile.regions.map((region, i) => (
                        <Badge key={i} variant="outline">
                          {region}
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <p className="text-sm text-muted-foreground">No regions assigned</p>
                  )}
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Recent Leads */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Calendar className="h-5 w-5" />
              Recent Leads (Last 20)
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <div className="divide-y">
              {recentLeads.length > 0 ? (
                recentLeads.map((lead) => (
                  <div
                    key={lead.id}
                    className="flex items-center justify-between p-4 hover:bg-muted/50 transition-colors"
                  >
                    <div className="flex items-center gap-3">
                      <div>
                        <div className="font-medium">{lead.name || 'Unknown'}</div>
                        <div className="text-xs text-muted-foreground">
                          {formatDate(lead.created_at)}
                        </div>
                      </div>
                    </div>
                    <div className="flex items-center gap-4">
                      <span
                        className={`text-sm font-medium ${statusColors[lead.status] || 'text-muted-foreground'}`}
                      >
                        {lead.status}
                      </span>
                      {Number(lead.amount) > 0 && (
                        <span className="text-sm font-medium">
                          ₱{Number(lead.amount).toLocaleString()}
                        </span>
                      )}
                    </div>
                  </div>
                ))
              ) : (
                <div className="flex flex-col items-center justify-center py-12">
                  <Target className="h-12 w-12 text-muted-foreground/50" />
                  <h3 className="mt-4 text-lg font-semibold">No leads yet</h3>
                  <p className="text-muted-foreground">This agent has no assigned leads.</p>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
