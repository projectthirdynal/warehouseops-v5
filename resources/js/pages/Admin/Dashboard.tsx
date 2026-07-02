import { Head, useForm, router, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Users,
  Shield,
  Activity,
  CheckCircle,
  XCircle,
  BarChart3,
  RefreshCw,
  UserPlus,
  Save,
  Trash2,
  Eye,
} from 'lucide-react';
import { useState, useMemo, useCallback } from 'react';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

import ActivityFeed from './components/ActivityFeed';
import type { UserRecord, ModuleDefinition, ActivityItem } from './types';
import { ROLE_COLORS } from './constants';

interface Props {
  users: UserRecord[];
  roles: string[];
  modules: ModuleDefinition[];
  userModules: Record<number, Record<string, boolean>>;
  stats: {
    total_users: number;
    active_users: number;
    inactive_users: number;
    role_distribution: Record<string, number>;
  };
  recentActivity: ActivityItem[];
}

/* ─────────────────────────────────────────────── */
/*  Main Component                                 */
/* ─────────────────────────────────────────────── */

export default function AdminDashboard({
  users,
  roles,
  modules,
  userModules,
  stats,
  recentActivity,
}: Props) {
  const { auth } = usePage<PageProps>().props;
  const isSuperadmin = auth.user.role === 'superadmin';

  const [activeTab, setActiveTab] = useState('users');
  const [showAddUserDialog, setShowAddUserDialog] = useState(false);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');

  /* Local editable copy of module access per user */
  const [localModules, setLocalModules] =
    useState<Record<number, Record<string, boolean>>>(userModules);
  const [savingUser, setSavingUser] = useState<number | null>(null);
  const [dirtyUsers, setDirtyUsers] = useState<Set<number>>(new Set());

  /* Group modules by section for column headers */
  const sections = useMemo(() => {
    const map = new Map<string, ModuleDefinition[]>();
    for (const mod of modules) {
      if (!map.has(mod.section)) map.set(mod.section, []);
      map.get(mod.section)!.push(mod);
    }
    return map;
  }, [modules]);

  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      if (roleFilter !== 'all' && u.role !== roleFilter) return false;
      if (
        search &&
        !u.name.toLowerCase().includes(search.toLowerCase()) &&
        !u.email.toLowerCase().includes(search.toLowerCase())
      )
        return false;
      return true;
    });
  }, [users, search, roleFilter]);

  const toggleModule = useCallback((userId: number, moduleKey: string) => {
    setLocalModules((prev) => ({
      ...prev,
      [userId]: {
        ...(prev[userId] ?? {}),
        [moduleKey]: !(prev[userId]?.[moduleKey] ?? false),
      },
    }));
    setDirtyUsers((prev) => new Set(prev).add(userId));
  }, []);

  const saveUserModules = (userId: number) => {
    setSavingUser(userId);
    router.patch(
      `/admin/users/${userId}/modules`,
      {
        modules: localModules[userId] ?? {},
      },
      {
        preserveScroll: true,
        onFinish: () => {
          setSavingUser(null);
          setDirtyUsers((prev) => {
            const s = new Set(prev);
            s.delete(userId);
            return s;
          });
        },
      }
    );
  };

  const handleToggleActive = (userId: number) => {
    router.post(`/admin/users/${userId}/toggle`, {}, { preserveScroll: true });
  };

  const handleRoleChange = (userId: number, newRole: string) => {
    router.patch(`/admin/users/${userId}/role`, { role: newRole }, { preserveScroll: true });
  };

  const handleDelete = (user: UserRecord) => {
    if (!confirm(`Delete user "${user.name}"? This cannot be undone.`)) return;
    router.delete(`/admin/users/${user.id}`, { preserveScroll: true });
  };

  const roleDistributionData = Object.entries(stats.role_distribution).sort((a, b) => b[1] - a[1]);

  return (
    <AppLayout>
      <Head title="Admin Dashboard" />
      <div className="space-y-4 p-6">
        {/* Header */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight font-display">Admin Dashboard</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Manage users, roles, and module access.
            </p>
          </div>
          <div className="flex gap-2">
            <Button onClick={() => setShowAddUserDialog(true)}>
              <UserPlus className="mr-1.5 h-4 w-4" /> Add User
            </Button>
            <Button variant="outline" onClick={() => window.location.reload()}>
              <RefreshCw className="mr-1.5 h-4 w-4" /> Refresh
            </Button>
          </div>
        </div>

        {/* Stats */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            icon={<Users className="h-5 w-5" />}
            label="Total Users"
            value={stats.total_users}
            accent="blue"
          />
          <StatCard
            icon={<CheckCircle className="h-5 w-5" />}
            label="Active Users"
            value={stats.active_users}
            accent="green"
          />
          <StatCard
            icon={<XCircle className="h-5 w-5" />}
            label="Inactive Users"
            value={stats.inactive_users}
            accent="red"
          />
          <StatCard
            icon={<Shield className="h-5 w-5" />}
            label="Roles"
            value={roles.length}
            accent="purple"
          />
        </div>

        {/* Role Distribution */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <BarChart3 className="h-4 w-4 text-muted-foreground" /> Role Distribution
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex h-7 w-full overflow-hidden rounded-full bg-muted">
              {roleDistributionData.map(([role, count]) => {
                const pct = (count / stats.total_users) * 100;
                return (
                  <div
                    key={role}
                    className={cn(
                      'h-full transition-all',
                      ROLE_COLORS[role]?.split(' ')[0] ?? 'bg-muted-foreground/20'
                    )}
                    style={{ width: `${pct}%` }}
                    title={`${role}: ${count} (${pct.toFixed(1)}%)`}
                  />
                );
              })}
            </div>
            <div className="mt-2 flex flex-wrap gap-3">
              {roleDistributionData.map(([role, count]) => (
                <div key={role} className="flex items-center gap-1.5">
                  <div
                    className={cn(
                      'h-2.5 w-2.5 rounded-full',
                      ROLE_COLORS[role]?.split(' ')[0] ?? 'bg-muted-foreground/20'
                    )}
                  />
                  <span className="text-xs capitalize">{role}</span>
                  <span className="text-xs font-bold text-muted-foreground">{count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Tabs */}
        <Tabs value={activeTab} onValueChange={setActiveTab}>
          <TabsList className="w-full sm:w-auto">
            <TabsTrigger value="users" className="gap-2">
              <Users className="h-4 w-4" /> Users
            </TabsTrigger>
            <TabsTrigger value="activity" className="gap-2">
              <Activity className="h-4 w-4" /> Activity Log
            </TabsTrigger>
          </TabsList>

          {/* ── USERS + INLINE MODULE MATRIX ── */}
          <TabsContent value="users" className="space-y-3 mt-4">
            {/* Filters */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <div className="relative flex-1 max-w-sm">
                <Input
                  placeholder="Search by name or email..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="pl-3"
                />
              </div>
              <Select value={roleFilter} onValueChange={setRoleFilter}>
                <SelectTrigger className="w-40">
                  <SelectValue placeholder="All Roles" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Roles</SelectItem>
                  {roles.map((r) => (
                    <SelectItem key={r} value={r} className="capitalize">
                      {r}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Scrollable Matrix Table */}
            <div className="overflow-x-auto rounded-lg border border-border">
              <table className="min-w-max text-sm border-collapse">
                <thead>
                  {/* Section header row */}
                  <tr className="border-b border-border bg-muted/60">
                    <th className="sticky left-0 z-20 bg-muted/80 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide min-w-[220px]">
                      User
                    </th>
                    <th className="px-3 py-2 text-xs font-semibold uppercase tracking-wide min-w-[120px] text-left">
                      Role
                    </th>
                    <th className="px-3 py-2 text-xs font-semibold uppercase tracking-wide min-w-[70px] text-center">
                      Status
                    </th>
                    {Array.from(sections.entries()).map(([section, mods]) => (
                      <th
                        key={section}
                        colSpan={mods.length}
                        className="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide border-l border-border"
                      >
                        {section}
                      </th>
                    ))}
                    <th className="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-center border-l border-border min-w-[100px]">
                      Actions
                    </th>
                  </tr>
                  {/* Module label row */}
                  <tr className="border-b border-border bg-muted/30">
                    <th className="sticky left-0 z-20 bg-muted/60 px-4 py-1" />
                    <th className="px-3 py-1" />
                    <th className="px-3 py-1" />
                    {modules.map((mod) => (
                      <th key={mod.key} className="px-2 py-1 text-center border-l border-border/40">
                        <span
                          className="inline-block max-w-[70px] truncate text-[10px] text-muted-foreground font-normal"
                          title={mod.label}
                        >
                          {mod.label}
                        </span>
                      </th>
                    ))}
                    <th className="px-3 py-1 border-l border-border" />
                  </tr>
                </thead>
                <tbody>
                  {filteredUsers.map((user, idx) => {
                    const isSuperadminRow = user.role === 'superadmin';
                    const canEdit = isSuperadmin || !isSuperadminRow;
                    const isDirty = dirtyUsers.has(user.id);
                    const isSaving = savingUser === user.id;

                    return (
                      <tr
                        key={user.id}
                        className={cn(
                          'border-b border-border/50 transition-colors',
                          idx % 2 === 0 ? 'bg-background' : 'bg-muted/10',
                          !user.is_active && 'opacity-50'
                        )}
                      >
                        {/* User info */}
                        <td className="sticky left-0 z-10 bg-inherit px-4 py-2 min-w-[220px]">
                          <div className="flex items-center gap-2.5">
                            <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
                              {user.name.charAt(0).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                              <p className="truncate font-medium text-sm leading-tight">
                                {user.name}
                              </p>
                              <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                            </div>
                          </div>
                        </td>

                        {/* Role dropdown */}
                        <td className="px-3 py-2 min-w-[120px]">
                          <Select
                            value={user.role}
                            onValueChange={(val) => handleRoleChange(user.id, val)}
                            disabled={!canEdit}
                          >
                            <SelectTrigger
                              className={cn('h-6 w-28 text-xs border px-2', ROLE_COLORS[user.role])}
                            >
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {roles.map((r) => (
                                <SelectItem key={r} value={r} className="capitalize text-xs">
                                  {r}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </td>

                        {/* Status toggle */}
                        <td className="px-3 py-2 text-center min-w-[70px]">
                          <Switch
                            checked={user.is_active}
                            onCheckedChange={() => handleToggleActive(user.id)}
                            disabled={!canEdit}
                            className="scale-75"
                          />
                        </td>

                        {/* Module checkboxes */}
                        {modules.map((mod) => {
                          const granted = isSuperadminRow
                            ? true
                            : (localModules[user.id]?.[mod.key] ?? false);
                          return (
                            <td
                              key={mod.key}
                              className="px-2 py-2 text-center border-l border-border/30"
                            >
                              <Checkbox
                                checked={granted}
                                onCheckedChange={() => toggleModule(user.id, mod.key)}
                                disabled={isSuperadminRow || !canEdit}
                                className="h-4 w-4"
                              />
                            </td>
                          );
                        })}

                        {/* Actions */}
                        <td className="px-3 py-2 text-center border-l border-border/40 min-w-[100px]">
                          <div className="flex items-center justify-center gap-1">
                            {isDirty && (
                              <Button
                                size="sm"
                                variant="default"
                                className="h-6 px-2 text-xs"
                                disabled={isSaving}
                                onClick={() => saveUserModules(user.id)}
                              >
                                <Save className="h-3 w-3 mr-1" />
                                {isSaving ? '...' : 'Save'}
                              </Button>
                            )}
                            {!isDirty && (
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-6 w-6 p-0"
                                onClick={() => {}}
                                title="View user"
                              >
                                <Eye className="h-3.5 w-3.5" />
                              </Button>
                            )}
                            <Button
                              size="sm"
                              variant="ghost"
                              className="h-6 w-6 p-0 text-destructive hover:text-destructive"
                              disabled={!canEdit}
                              onClick={() => handleDelete(user)}
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}

                  {filteredUsers.length === 0 && (
                    <tr>
                      <td
                        colSpan={modules.length + 5}
                        className="py-12 text-center text-muted-foreground"
                      >
                        No users found.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </TabsContent>

          {/* Activity Log */}
          <TabsContent value="activity" className="mt-4">
            <ActivityFeed logs={recentActivity} />
          </TabsContent>
        </Tabs>
      </div>

      {/* Add User Dialog */}
      <AddUserDialog
        open={showAddUserDialog}
        onClose={() => setShowAddUserDialog(false)}
        roles={roles}
      />
    </AppLayout>
  );
}

/* ─────────────────────────────────────────────── */
/*  Sub-components                                 */
/* ─────────────────────────────────────────────── */

function StatCard({
  icon,
  label,
  value,
  accent,
}: {
  icon: React.ReactNode;
  label: string;
  value: number;
  accent: 'blue' | 'green' | 'red' | 'purple';
}) {
  const borderMap = {
    blue: 'border-l-primary',
    green: 'border-l-green-500',
    red: 'border-l-red-500',
    purple: 'border-l-purple-500',
  };
  const iconMap = {
    blue: 'text-primary',
    green: 'text-success',
    red: 'text-destructive',
    purple: 'text-primary',
  };
  return (
    <Card className={cn('border-l-4', borderMap[accent])}>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              {label}
            </p>
            <p className="mt-1 text-xl font-bold font-display tabular-nums">{value}</p>
          </div>
          <div className={cn('rounded-full bg-muted p-2.5', iconMap[accent])}>{icon}</div>
        </div>
      </CardContent>
    </Card>
  );
}

function AddUserDialog({
  open,
  onClose,
  roles,
}: {
  open: boolean;
  onClose: () => void;
  roles: string[];
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    email: '',
    phone: '',
    role: 'agent',
    password: '',
  });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    post('/admin/users', {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <UserPlus className="h-5 w-5" /> Create User
          </DialogTitle>
          <DialogDescription>
            Add a new user account. If role is Agent, a profile is auto-created.
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="text-sm font-medium">Full Name</label>
            <Input
              value={data.name}
              onChange={(e) => setData('name', e.target.value)}
              placeholder="Juan dela Cruz"
              required
            />
            {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Email</label>
            <Input
              type="email"
              value={data.email}
              onChange={(e) => setData('email', e.target.value)}
              placeholder="user@company.com"
              required
            />
            {errors.email && <p className="mt-1 text-xs text-destructive">{errors.email}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Role</label>
            <Select value={data.role} onValueChange={(v) => setData('role', v)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {roles.map((r) => (
                  <SelectItem key={r} value={r} className="capitalize">
                    {r}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.role && <p className="mt-1 text-xs text-destructive">{errors.role}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Password</label>
            <Input
              type="password"
              value={data.password}
              onChange={(e) => setData('password', e.target.value)}
              placeholder="Min 8 characters"
              required
            />
            {errors.password && <p className="mt-1 text-xs text-destructive">{errors.password}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">
              Phone <span className="text-muted-foreground">(optional)</span>
            </label>
            <Input
              type="tel"
              value={data.phone}
              onChange={(e) => setData('phone', e.target.value)}
              placeholder="+63 9XX XXX XXXX"
            />
            {errors.phone && <p className="mt-1 text-xs text-destructive">{errors.phone}</p>}
          </div>
          <div className="flex gap-3 pt-2">
            <Button type="button" variant="outline" className="flex-1" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" className="flex-1" disabled={processing}>
              {processing ? 'Creating...' : 'Create User'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
