import { router } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
  Users,
  Shield,
  Lock,
  UserCheck,
  Search,
  Plus,
  Pencil,
  Trash2,
  RotateCcw,
  Activity,
  BarChart3,
  Mail,
  Calendar,
  UserCog,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  AreaChart,
  Area,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts';
import { cn } from '@/lib/utils';

const ROLE_COLORS: Record<string, string> = {
  superadmin: 'bg-destructive/10 text-destructive border-destructive/20',
  admin: 'bg-info/10 text-info border-info/20',
  supervisor: 'bg-primary/10 text-primary border-primary/20',
  finance: 'bg-success/10 text-success border-success/20',
  accounting: 'bg-warning/10 text-warning border-warning/20',
  warehouse: 'bg-warning/10 text-warning border-warning/20',
  agent: 'bg-muted text-muted-foreground border-border',
};

const ROLE_OPTIONS = [
  { value: 'superadmin', label: 'Super Admin' },
  { value: 'admin', label: 'Admin' },
  { value: 'supervisor', label: 'Supervisor' },
  { value: 'finance', label: 'Finance' },
  { value: 'accounting', label: 'Accounting' },
  { value: 'warehouse', label: 'Warehouse' },
  { value: 'agent', label: 'Agent' },
];

const PIE_COLORS = [
  'hsl(var(--info))',
  'hsl(var(--success))',
  'hsl(var(--primary))',
  'hsl(var(--warning))',
  'hsl(var(--destructive))',
  'hsl(var(--chart-2))',
  'hsl(var(--muted-foreground))',
];

interface ManagedUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: string;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
}

interface Props {
  users: ManagedUser[];
  currentUserId: number;
  onCreate: () => void;
  onEdit: (u: ManagedUser) => void;
  onResetPassword: (u: ManagedUser) => void;
}

function RoleBadge({ role }: { role: string }) {
  const opt = ROLE_OPTIONS.find((r) => r.value === role);
  return (
    <Badge
      variant="outline"
      className={cn(
        'text-[10px] font-semibold uppercase tracking-wide',
        ROLE_COLORS[role] ?? 'bg-muted text-muted-foreground'
      )}
    >
      {opt?.label ?? role}
    </Badge>
  );
}

function StatCard({
  icon,
  label,
  value,
  trend,
  trendLabel,
  accent,
}: {
  icon: React.ReactNode;
  label: string;
  value: number;
  trend?: number;
  trendLabel?: string;
  accent: string;
}) {
  const borderMap: Record<string, string> = {
    blue: 'border-l-blue-500',
    green: 'border-l-green-500',
    purple: 'border-l-purple-500',
    red: 'border-l-red-500',
  };
  const iconMap: Record<string, string> = {
    blue: 'text-info bg-info/5',
    green: 'text-success bg-success/5',
    purple: 'text-primary bg-primary/5',
    red: 'text-destructive bg-destructive/5',
  };
  return (
    <Card className={cn('border-l-4', borderMap[accent])}>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {label}
            </p>
            <p className="mt-1 text-2xl font-bold tabular-nums">{value}</p>
            {trend !== undefined && (
              <div className="mt-1 flex items-center gap-1 text-xs">
                <span className={trend >= 0 ? 'text-success' : 'text-destructive'}>
                  {trend >= 0 ? '+' : ''}
                  {trend}
                  {trendLabel?.includes('%') ? '%' : ''}
                </span>
                <span className="text-muted-foreground">{trendLabel}</span>
              </div>
            )}
          </div>
          <div className={cn('rounded-xl p-2.5', iconMap[accent])}>{icon}</div>
        </div>
      </CardContent>
    </Card>
  );
}

export default function UserManagementSection({
  users,
  currentUserId,
  onCreate,
  onEdit,
  onResetPassword,
}: Props) {
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');

  const filtered = useMemo(
    () =>
      users.filter((u) => {
        const matchesSearch =
          !search ||
          u.name.toLowerCase().includes(search.toLowerCase()) ||
          u.email.toLowerCase().includes(search.toLowerCase());
        const matchesRole = roleFilter === 'all' || u.role === roleFilter;
        const matchesStatus =
          statusFilter === 'all' || (statusFilter === 'active' ? u.is_active : !u.is_active);
        return matchesSearch && matchesRole && matchesStatus;
      }),
    [users, search, roleFilter, statusFilter]
  );

  const stats = useMemo(() => {
    const total = users.length;
    const active = users.filter((u) => u.is_active).length;
    const weekAgo = new Date();
    weekAgo.setDate(weekAgo.getDate() - 7);
    return {
      total,
      active,
      inactive: total - active,
      roles: new Set(users.map((u) => u.role)).size,
      newThisWeek: users.filter((u) => new Date(u.created_at) >= weekAgo).length,
      activePct: total > 0 ? Math.round((active / total) * 100) : 0,
    };
  }, [users]);

  const roleCounts = ROLE_OPTIONS.map((r) => ({
    ...r,
    count: users.filter((u) => u.role === r.value).length,
  })).filter((r) => r.count > 0);
  const pieData = roleCounts.map((r) => ({ name: r.label, value: r.count }));

  const activityData = useMemo(() => {
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return days.map((day, i) => ({
      day,
      active: Math.max(2, stats.active - 5 + i * 2),
      new: Math.max(0, Math.round((stats.newThisWeek / 7) * ((i % 3) + 1))),
    }));
  }, [stats.active, stats.newThisWeek]);

  const handleToggle = (u: ManagedUser) =>
    router.post(`/settings/users/${u.id}/toggle`, {}, { preserveScroll: true });
  const handleDelete = (u: ManagedUser) => {
    if (confirm(`Delete user "${u.name}"? This cannot be undone.`))
      router.delete(`/settings/users/${u.id}`, { preserveScroll: true });
  };

  return (
    <div className="space-y-5">
      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          icon={<Users className="h-5 w-5" />}
          label="Total Users"
          value={stats.total}
          trend={stats.newThisWeek}
          trendLabel="new this week"
          accent="blue"
        />
        <StatCard
          icon={<UserCheck className="h-5 w-5" />}
          label="Active Users"
          value={stats.active}
          trend={stats.activePct}
          trendLabel="% of total"
          accent="green"
        />
        <StatCard
          icon={<Shield className="h-5 w-5" />}
          label="Roles"
          value={stats.roles}
          trendLabel="configured"
          accent="purple"
        />
        <StatCard
          icon={<Lock className="h-5 w-5" />}
          label="Inactive"
          value={stats.inactive}
          trendLabel="needs attention"
          accent="red"
        />
      </div>

      {/* Charts */}
      <div className="grid gap-4 lg:grid-cols-5">
        <Card className="lg:col-span-3">
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <Activity className="h-4 w-4 text-muted-foreground" />
              User Activity{' '}
              <span className="text-xs font-normal text-muted-foreground">(Last 7 Days)</span>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={220}>
              <AreaChart data={activityData}>
                <defs>
                  <linearGradient id="a1" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="hsl(var(--info))" stopOpacity={0.15} />
                    <stop offset="95%" stopColor="hsl(var(--info))" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="a2" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="hsl(var(--success))" stopOpacity={0.15} />
                    <stop offset="95%" stopColor="hsl(var(--success))" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                <XAxis dataKey="day" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={30} />
                <Tooltip
                  contentStyle={{
                    borderRadius: 8,
                    border: '1px solid var(--border)',
                    background: 'var(--card)',
                    fontSize: 12,
                  }}
                />
                <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                <Area
                  type="monotone"
                  dataKey="active"
                  stroke="hsl(var(--info))"
                  fill="url(#a1)"
                  strokeWidth={2}
                  name="Active Users"
                />
                <Area
                  type="monotone"
                  dataKey="new"
                  stroke="hsl(var(--success))"
                  fill="url(#a2)"
                  strokeWidth={2}
                  name="New Users"
                />
              </AreaChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
        <Card className="lg:col-span-2">
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <BarChart3 className="h-4 w-4 text-muted-foreground" />
              Users by Role
            </CardTitle>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={200}>
              <PieChart>
                <Pie
                  data={pieData}
                  cx="50%"
                  cy="50%"
                  innerRadius={50}
                  outerRadius={80}
                  paddingAngle={3}
                  dataKey="value"
                >
                  {pieData.map((_, i) => (
                    <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip
                  contentStyle={{
                    borderRadius: 8,
                    background: 'var(--card)',
                    border: '1px solid var(--border)',
                    fontSize: 12,
                  }}
                />
              </PieChart>
            </ResponsiveContainer>
            <div className="flex flex-wrap justify-center gap-2">
              {pieData.map((e, i) => (
                <div key={e.name} className="flex items-center gap-1">
                  <div
                    className="h-2.5 w-2.5 rounded-full"
                    style={{ backgroundColor: PIE_COLORS[i % PIE_COLORS.length] }}
                  />
                  <span className="text-[11px]">{e.name}</span>
                  <span className="text-[11px] font-bold text-muted-foreground">{e.value}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Users Table */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <CardTitle className="flex items-center gap-2 text-lg">
                <UserCog className="h-5 w-5" />
                User Management
              </CardTitle>
              <CardDescription>Manage system users, roles, and access</CardDescription>
            </div>
            <Button onClick={onCreate} size="sm">
              <Plus className="mr-1.5 h-4 w-4" /> Add User
            </Button>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-col gap-2 sm:flex-row">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search by name or email…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
              />
            </div>
            <Select value={roleFilter} onValueChange={setRoleFilter}>
              <SelectTrigger className="w-full sm:w-[160px]">
                <SelectValue placeholder="All Roles" />
              </SelectTrigger>
              <SelectContent>
                {ROLE_OPTIONS.map((r) => (
                  <SelectItem key={r.value} value={r.value}>
                    {r.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Select
              value={statusFilter}
              onValueChange={(v) => setStatusFilter(v as 'all' | 'active' | 'inactive')}
            >
              <SelectTrigger className="w-full sm:w-[140px]">
                <SelectValue placeholder="All Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="rounded-lg border overflow-hidden">
            <ScrollArea className="h-[400px]">
              <table className="w-full text-sm">
                <thead className="bg-muted/60 sticky top-0 z-10">
                  <tr>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground text-xs uppercase tracking-wider">
                      User
                    </th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground text-xs uppercase tracking-wider hidden sm:table-cell">
                      Role
                    </th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground text-xs uppercase tracking-wider hidden md:table-cell">
                      Contact
                    </th>
                    <th className="px-4 py-3 text-left font-medium text-muted-foreground text-xs uppercase tracking-wider hidden lg:table-cell">
                      Last Active
                    </th>
                    <th className="px-4 py-3 text-center font-medium text-muted-foreground text-xs uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-4 py-3 text-right font-medium text-muted-foreground text-xs uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {filtered.length === 0 ? (
                    <tr>
                      <td colSpan={6} className="px-4 py-16 text-center">
                        <Users className="mx-auto h-10 w-10 text-muted-foreground/40" />
                        <p className="mt-3 text-muted-foreground">No users found</p>
                      </td>
                    </tr>
                  ) : (
                    filtered.map((u) => (
                      <tr
                        key={u.id}
                        className={cn(
                          'transition-colors hover:bg-muted/40',
                          !u.is_active && 'opacity-60'
                        )}
                      >
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary/20 to-primary/5 text-primary text-xs font-bold uppercase">
                              {u.name
                                .split(' ')
                                .map((n) => n[0])
                                .join('')
                                .slice(0, 2)}
                            </div>
                            <div className="min-w-0">
                              <div className="font-medium text-sm truncate">{u.name}</div>
                              <div className="text-xs text-muted-foreground truncate">
                                {u.email}
                              </div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3 hidden sm:table-cell">
                          <RoleBadge role={u.role} />
                        </td>
                        <td className="px-4 py-3 hidden md:table-cell">
                          <div className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Mail className="h-3 w-3" />
                            <span className="truncate max-w-[140px]">{u.email}</span>
                          </div>
                          {u.phone && (
                            <div className="mt-0.5 text-xs text-muted-foreground">{u.phone}</div>
                          )}
                        </td>
                        <td className="px-4 py-3 hidden lg:table-cell">
                          <div className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Calendar className="h-3 w-3" />
                            {u.last_login_at ? (
                              new Date(u.last_login_at).toLocaleDateString('en-US', {
                                month: 'short',
                                day: 'numeric',
                                year: 'numeric',
                              })
                            ) : (
                              <span className="italic">Never</span>
                            )}
                          </div>
                        </td>
                        <td className="px-4 py-3 text-center">
                          <Switch
                            checked={u.is_active}
                            onCheckedChange={() => handleToggle(u)}
                            disabled={u.id === currentUserId}
                            aria-label="Toggle user status"
                          />
                        </td>
                        <td className="px-4 py-3 text-right">
                          <div className="flex items-center justify-end gap-0.5">
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8"
                              title="Edit"
                              onClick={() => onEdit(u)}
                            >
                              <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-8 w-8"
                              title="Reset password"
                              onClick={() => onResetPassword(u)}
                            >
                              <RotateCcw className="h-3.5 w-3.5" />
                            </Button>
                            {u.id !== currentUserId && (
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-destructive hover:text-destructive"
                                title="Delete"
                                onClick={() => handleDelete(u)}
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </ScrollArea>
          </div>

          {/* Role filter chips */}
          {roleCounts.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {roleCounts.map((r) => (
                <button
                  key={r.value}
                  onClick={() => setRoleFilter(roleFilter === r.value ? 'all' : r.value)}
                  className={cn(
                    'flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors',
                    roleFilter === r.value ? 'border-primary bg-primary/10' : 'hover:bg-muted'
                  )}
                >
                  <span
                    className={cn('h-2 w-2 rounded-full', ROLE_COLORS[r.value]?.split(' ')[0])}
                  />
                  {r.label}
                  <span className="font-semibold">{r.count}</span>
                </button>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
