import { Head, router } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import {
  Users, Shield, Activity, Search, CheckCircle, XCircle,
  UserCheck, UserX, BarChart3, Lock, Eye, Loader2,
  Filter, RefreshCw,
} from 'lucide-react';
import { useState, useMemo } from 'react';
import { cn } from '@/lib/utils';

/* ── Types ─────────────────────────────────────────── */

interface UserRecord {
  id: number;
  name: string;
  email: string;
  role: string;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
}

interface PermissionItem {
  id: number;
  key: string;
  label: string;
  section: string;
  description: string | null;
}

interface ActivityItem {
  id: number;
  user_id: number | null;
  user: { name: string; email: string } | null;
  action: string;
  entity_type: string | null;
  entity_id: number | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

interface Props {
  users: UserRecord[];
  roles: string[];
  permissions: Record<string, PermissionItem[]>;
  rolePermissions: Record<string, number[]>;
  stats: {
    total_users: number;
    active_users: number;
    inactive_users: number;
    role_distribution: Record<string, number>;
  };
  recentActivity: ActivityItem[];
}

/* ── Constants ───────────────────────────────────── */

const ROLE_COLORS: Record<string, string> = {
  superadmin: 'bg-red-100 text-red-700 border-red-200',
  admin: 'bg-blue-100 text-blue-700 border-blue-200',
  supervisor: 'bg-purple-100 text-purple-700 border-purple-200',
  finance: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  accounting: 'bg-amber-100 text-amber-700 border-amber-200',
  warehouse: 'bg-orange-100 text-orange-700 border-orange-200',
  agent: 'bg-gray-100 text-gray-700 border-gray-200',
};

const ACTION_ICONS: Record<string, typeof Users> = {
  'user.activated': UserCheck,
  'user.deactivated': UserX,
  'user.role_changed': Shield,
  'permissions.updated': Lock,
};

const ACTION_LABELS: Record<string, string> = {
  'user.activated': 'Activated user',
  'user.deactivated': 'Deactivated user',
  'user.role_changed': 'Changed user role',
  'permissions.updated': 'Updated role permissions',
};

/* ── Main Component ──────────────────────────────── */

export default function AdminDashboard({ users, roles, permissions, rolePermissions, stats, recentActivity }: Props) {
  const [activeTab, setActiveTab] = useState('users');
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [selectedRole, setSelectedRole] = useState<string>(roles[0] ?? 'superadmin');
  const [savingPermissions, setSavingPermissions] = useState(false);
  const [selectedPermissionIds, setSelectedPermissionIds] = useState<Record<string, number[]>>(rolePermissions);
  const [showUserDialog, setShowUserDialog] = useState(false);
  const [selectedUser, setSelectedUser] = useState<UserRecord | null>(null);

  const filteredUsers = useMemo(() => {
    return users.filter(u => {
      if (search && !u.name.toLowerCase().includes(search.toLowerCase()) && !u.email.toLowerCase().includes(search.toLowerCase())) return false;
      if (roleFilter !== 'all' && u.role !== roleFilter) return false;
      if (statusFilter === 'active' && !u.is_active) return false;
      if (statusFilter === 'inactive' && u.is_active) return false;
      return true;
    });
  }, [users, search, roleFilter, statusFilter]);

  const handleToggleUser = (userId: number) => {
    router.post(`/admin/users/${userId}/toggle`, {}, { preserveScroll: true });
  };

  const handleRoleChange = (userId: number, newRole: string) => {
    router.patch(`/admin/users/${userId}/role`, { role: newRole }, { preserveScroll: true });
  };

  const togglePermission = (role: string, permissionId: number) => {
    setSelectedPermissionIds(prev => {
      const current = new Set(prev[role] ?? []);
      if (current.has(permissionId)) {
        current.delete(permissionId);
      } else {
        current.add(permissionId);
      }
      return { ...prev, [role]: Array.from(current) };
    });
  };

  const saveRolePermissions = (role: string) => {
    setSavingPermissions(true);
    router.post('/admin/roles/permissions', {
      role,
      permissions: selectedPermissionIds[role] ?? [],
    }, {
      onFinish: () => setSavingPermissions(false),
      preserveScroll: true,
    });
  };

  const roleDistributionData = Object.entries(stats.role_distribution).sort((a, b) => b[1] - a[1]);

  return (
    <AppLayout>
      <Head title="Admin Dashboard" />
      <div className="space-y-6 p-6">

        {/* Header */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Admin Dashboard</h1>
            <p className="mt-1 text-sm text-muted-foreground">
              Manage users, roles, permissions, and system settings.
            </p>
          </div>
          <Button variant="outline" onClick={() => window.location.reload()}>
            <RefreshCw className="mr-2 h-4 w-4" /> Refresh
          </Button>
        </div>

        {/* Stats Cards */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard icon={<Users className="h-5 w-5" />} label="Total Users" value={stats.total_users} accent="blue" />
          <StatCard icon={<CheckCircle className="h-5 w-5" />} label="Active Users" value={stats.active_users} accent="green" />
          <StatCard icon={<XCircle className="h-5 w-5" />} label="Inactive Users" value={stats.inactive_users} accent="red" />
          <StatCard icon={<Shield className="h-5 w-5" />} label="Roles" value={roles.length} accent="purple" />
        </div>

        {/* Role Distribution Bar */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <BarChart3 className="h-4 w-4 text-muted-foreground" />
              Role Distribution
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex h-8 w-full overflow-hidden rounded-full bg-muted">
              {roleDistributionData.map(([role, count]) => {
                const pct = (count / stats.total_users) * 100;
                return (
                  <div
                    key={role}
                    className={cn('h-full transition-all', ROLE_COLORS[role]?.split(' ')[0] ?? 'bg-gray-200')}
                    style={{ width: `${pct}%` }}
                    title={`${role}: ${count} (${pct.toFixed(1)}%)`}
                  />
                );
              })}
            </div>
            <div className="mt-3 flex flex-wrap gap-3">
              {roleDistributionData.map(([role, count]) => (
                <div key={role} className="flex items-center gap-1.5">
                  <div className={cn('h-3 w-3 rounded-full', ROLE_COLORS[role]?.split(' ')[0] ?? 'bg-gray-200')} />
                  <span className="text-xs capitalize">{role.replace('_', ' ')}</span>
                  <span className="text-xs font-bold text-muted-foreground">{count}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        {/* Main Tabs */}
        <Tabs value={activeTab} onValueChange={setActiveTab}>
          <TabsList className="grid w-full grid-cols-3 sm:w-auto sm:inline-grid">
            <TabsTrigger value="users" className="gap-2">
              <Users className="h-4 w-4" /> Users
            </TabsTrigger>
            <TabsTrigger value="roles" className="gap-2">
              <Lock className="h-4 w-4" /> Roles & Permissions
            </TabsTrigger>
            <TabsTrigger value="activity" className="gap-2">
              <Activity className="h-4 w-4" /> Activity Log
            </TabsTrigger>
          </TabsList>

          {/* ── Users Tab ── */}
          <TabsContent value="users" className="space-y-4">
            {/* Filters */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="relative flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search by name or email..."
                  value={search}
                  onChange={e => setSearch(e.target.value)}
                  className="pl-9"
                />
              </div>
              <div className="flex gap-2">
                <Select value={roleFilter} onValueChange={setRoleFilter}>
                  <SelectTrigger className="w-36">
                    <Filter className="mr-1 h-3.5 w-3.5" />
                    <SelectValue placeholder="All Roles" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Roles</SelectItem>
                    {roles.map(r => <SelectItem key={r} value={r} className="capitalize">{r}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Select value={statusFilter} onValueChange={(v) => setStatusFilter(v as 'all' | 'active' | 'inactive')}>
                  <SelectTrigger className="w-36">
                    <SelectValue placeholder="Status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Status</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* Users Table */}
            <Card>
              <CardContent className="p-0">
                <ScrollArea className="h-[500px]">
                  <Table>
                    <TableHeader>
                      <TableRow className="hover:bg-transparent">
                        <TableHead className="w-[60px]">Status</TableHead>
                        <TableHead>User</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Last Login</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {filteredUsers.length === 0 ? (
                        <TableRow>
                          <TableCell colSpan={5} className="h-40 text-center text-muted-foreground">
                            <Users className="mx-auto h-8 w-8 opacity-50" />
                            <p className="mt-2">No users found</p>
                          </TableCell>
                        </TableRow>
                      ) : (
                        filteredUsers.map(user => (
                          <TableRow key={user.id} className={cn(!user.is_active && 'opacity-60')}>
                            <TableCell>
                              <Switch
                                checked={user.is_active}
                                onCheckedChange={() => handleToggleUser(user.id)}
                                aria-label="Toggle user status"
                              />
                            </TableCell>
                            <TableCell>
                              <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 font-semibold text-primary text-sm">
                                  {user.name.charAt(0).toUpperCase()}
                                </div>
                                <div>
                                  <p className="font-medium text-sm">{user.name}</p>
                                  <p className="text-xs text-muted-foreground">{user.email}</p>
                                </div>
                              </div>
                            </TableCell>
                            <TableCell>
                              <Select
                                value={user.role}
                                onValueChange={(val) => handleRoleChange(user.id, val)}
                              >
                                <SelectTrigger className={cn('h-7 w-36 text-xs border-0', ROLE_COLORS[user.role])}>
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  {roles.map(r => (
                                    <SelectItem key={r} value={r} className="capitalize text-xs">{r.replace('_', ' ')}</SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </TableCell>
                            <TableCell className="text-xs text-muted-foreground">
                              {user.last_login_at ? new Date(user.last_login_at).toLocaleDateString() : 'Never'}
                            </TableCell>
                            <TableCell className="text-right">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => { setSelectedUser(user); setShowUserDialog(true); }}
                              >
                                <Eye className="h-4 w-4" />
                              </Button>
                            </TableCell>
                          </TableRow>
                        ))
                      )}
                    </TableBody>
                  </Table>
                </ScrollArea>
              </CardContent>
            </Card>
          </TabsContent>

          {/* ── Roles & Permissions Tab ── */}
          <TabsContent value="roles" className="space-y-4">
            <div className="flex items-center gap-3">
              <label className="text-sm font-medium">Select Role:</label>
              <Select value={selectedRole} onValueChange={setSelectedRole}>
                <SelectTrigger className="w-48 capitalize">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {roles.map(r => (
                    <SelectItem key={r} value={r} className="capitalize">{r.replace('_', ' ')}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Button
                size="sm"
                onClick={() => saveRolePermissions(selectedRole)}
                disabled={savingPermissions}
              >
                {savingPermissions ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                Save Permissions
              </Button>
            </div>

            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
              {Object.entries(permissions).map(([section, perms]) => (
                <Card key={section}>
                  <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                      {section}
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {perms.map(perm => {
                      const isChecked = (selectedPermissionIds[selectedRole] ?? []).includes(perm.id);
                      return (
                        <div key={perm.id} className="flex items-start gap-2.5">
                          <Checkbox
                            id={`perm-${selectedRole}-${perm.id}`}
                            checked={isChecked}
                            onCheckedChange={() => togglePermission(selectedRole, perm.id)}
                            className="mt-0.5"
                          />
                          <label
                            htmlFor={`perm-${selectedRole}-${perm.id}`}
                            className="cursor-pointer text-sm leading-tight"
                          >
                            <span className="font-medium">{perm.label}</span>
                            {perm.description && (
                              <p className="text-xs text-muted-foreground mt-0.5">{perm.description}</p>
                            )}
                          </label>
                        </div>
                      );
                    })}
                  </CardContent>
                </Card>
              ))}
            </div>
          </TabsContent>

          {/* ── Activity Log Tab ── */}
          <TabsContent value="activity">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  <Activity className="h-4 w-4 text-muted-foreground" />
                  Recent Activity
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <ScrollArea className="h-[500px]">
                  {recentActivity.length === 0 ? (
                    <div className="flex flex-col items-center justify-center py-16">
                      <Activity className="h-10 w-10 text-muted-foreground/40" />
                      <p className="mt-3 text-muted-foreground">No activity recorded yet</p>
                    </div>
                  ) : (
                    <div className="divide-y">
                      {recentActivity.map(log => {
                        const Icon = ACTION_ICONS[log.action] ?? Activity;
                        const label = ACTION_LABELS[log.action] ?? log.action;
                        return (
                          <div key={log.id} className="flex items-start gap-3 px-4 py-3 hover:bg-muted/50">
                            <div className="mt-0.5 flex h-8 w-8 items-center justify-center rounded-full bg-primary/10">
                              <Icon className="h-4 w-4 text-primary" />
                            </div>
                            <div className="flex-1 min-w-0">
                              <p className="text-sm">
                                <span className="font-medium">{log.user?.name ?? 'System'}</span>
                                {' '}
                                <span className="text-muted-foreground">{label}</span>
                              </p>
                              {log.metadata && (
                                <p className="mt-0.5 text-xs text-muted-foreground truncate">
                                  {JSON.stringify(log.metadata).slice(0, 120)}
                                </p>
                              )}
                            </div>
                            <time className="text-xs text-muted-foreground whitespace-nowrap">
                              {new Date(log.created_at).toLocaleString()}
                            </time>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </ScrollArea>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>

      {/* User Detail Dialog */}
      <Dialog open={showUserDialog} onOpenChange={setShowUserDialog}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>User Details</DialogTitle>
            <DialogDescription>View user information and activity</DialogDescription>
          </DialogHeader>
          {selectedUser && (
            <div className="space-y-4">
              <div className="flex items-center gap-3">
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-lg font-bold text-primary">
                  {selectedUser.name.charAt(0).toUpperCase()}
                </div>
                <div>
                  <p className="font-semibold">{selectedUser.name}</p>
                  <p className="text-sm text-muted-foreground">{selectedUser.email}</p>
                </div>
              </div>
              <Separator />
              <div className="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <p className="text-muted-foreground text-xs uppercase tracking-wide">Role</p>
                  <Badge variant="outline" className={cn('mt-1 capitalize', ROLE_COLORS[selectedUser.role])}>
                    {selectedUser.role.replace('_', ' ')}
                  </Badge>
                </div>
                <div>
                  <p className="text-muted-foreground text-xs uppercase tracking-wide">Status</p>
                  <Badge variant={selectedUser.is_active ? 'default' : 'secondary'} className="mt-1">
                    {selectedUser.is_active ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
                <div>
                  <p className="text-muted-foreground text-xs uppercase tracking-wide">Last Login</p>
                  <p className="mt-1">{selectedUser.last_login_at ? new Date(selectedUser.last_login_at).toLocaleString() : 'Never'}</p>
                </div>
                <div>
                  <p className="text-muted-foreground text-xs uppercase tracking-wide">Created</p>
                  <p className="mt-1">{new Date(selectedUser.created_at).toLocaleDateString()}</p>
                </div>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}

/* ── Sub-components ──────────────────────────────── */

function StatCard({ icon, label, value, accent }: {
  icon: React.ReactNode;
  label: string;
  value: number;
  accent: 'blue' | 'green' | 'red' | 'purple';
}) {
  const borderMap = { blue: 'border-l-primary', green: 'border-l-green-500', red: 'border-l-red-500', purple: 'border-l-purple-500' };
  const iconMap = { blue: 'text-primary', green: 'text-green-600', red: 'text-red-600', purple: 'text-purple-600' };

  return (
    <Card className={cn('border-l-4', borderMap[accent])}>
      <CardContent className="p-4">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-bold tabular-nums">{value}</p>
          </div>
          <div className={cn('rounded-full bg-muted p-2.5', iconMap[accent])}>
            {icon}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
