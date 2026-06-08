import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import {
  Users, Shield, Activity, CheckCircle, XCircle,
  BarChart3, RefreshCw, UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

import UserTable from './components/UserTable';
import PermissionMatrix from './components/PermissionMatrix';
import ActivityFeed from './components/ActivityFeed';

import type { UserRecord, PermissionItem, ActivityItem } from './types';

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

import { ROLE_COLORS } from './constants';

/* ── Main Component ── */

export default function AdminDashboard({ users, roles, permissions, rolePermissions, stats, recentActivity }: Props) {
  const [activeTab, setActiveTab] = useState('users');
  const [showUserDialog, setShowUserDialog] = useState(false);
  const [showAddUserDialog, setShowAddUserDialog] = useState(false);
  const [selectedUser, setSelectedUser] = useState<UserRecord | null>(null);

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
          <div className="flex gap-2">
            <Button onClick={() => setShowAddUserDialog(true)}>
              <UserPlus className="mr-2 h-4 w-4" /> Add User
            </Button>
            <Button variant="outline" onClick={() => window.location.reload()}>
              <RefreshCw className="mr-2 h-4 w-4" /> Refresh
            </Button>
          </div>
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
              <Shield className="h-4 w-4" /> Roles & Permissions
            </TabsTrigger>
            <TabsTrigger value="activity" className="gap-2">
              <Activity className="h-4 w-4" /> Activity Log
            </TabsTrigger>
          </TabsList>

          <TabsContent value="users" className="space-y-4">
            <UserTable
              users={users}
              roles={roles}
              onViewUser={(user) => { setSelectedUser(user); setShowUserDialog(true); }}
            />
          </TabsContent>

          <TabsContent value="roles" className="space-y-4">
            <PermissionMatrix
              permissions={permissions}
              roles={roles}
              rolePermissions={rolePermissions}
            />
          </TabsContent>

          <TabsContent value="activity">
            <ActivityFeed logs={recentActivity} />
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
                  <p className="text-muted-foreground text-xs uppercase tracking-wide">Phone</p>
                  <p className="mt-1">{selectedUser.phone ?? '—'}</p>
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

      {/* Add User Dialog */}
      <AddUserDialog open={showAddUserDialog} onClose={() => setShowAddUserDialog(false)} roles={roles} />
    </AppLayout>
  );
}

/* ── Sub-components ── */

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

function AddUserDialog({ open, onClose, roles }: { open: boolean; onClose: () => void; roles: string[] }) {
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

  if (!open) return null;

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><UserPlus className="h-5 w-5" />Create User</DialogTitle>
          <DialogDescription>Add a new user account to the system.</DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <label className="text-sm font-medium">Full Name</label>
            <Input value={data.name} onChange={e => setData('name', e.target.value)} placeholder="Juan dela Cruz" required />
            {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Email Address</label>
            <Input type="email" value={data.email} onChange={e => setData('email', e.target.value)} placeholder="user@company.com" required />
            {errors.email && <p className="mt-1 text-xs text-destructive">{errors.email}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Role</label>
            <Select value={data.role} onValueChange={v => setData('role', v)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                {roles.map(r => (
                  <SelectItem key={r} value={r} className="capitalize">{r.replace('_', ' ')}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.role && <p className="mt-1 text-xs text-destructive">{errors.role}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Password</label>
            <Input type="password" value={data.password} onChange={e => setData('password', e.target.value)} placeholder="Min 8 characters" required />
            {errors.password && <p className="mt-1 text-xs text-destructive">{errors.password}</p>}
          </div>
          <div>
            <label className="text-sm font-medium">Phone <span className="text-muted-foreground">(optional)</span></label>
            <Input type="tel" value={data.phone} onChange={e => setData('phone', e.target.value)} placeholder="+63 9XX XXX XXXX" />
            {errors.phone && <p className="mt-1 text-xs text-destructive">{errors.phone}</p>}
          </div>
          <div className="flex gap-3 pt-2">
            <Button type="button" variant="outline" className="flex-1" onClick={onClose}>Cancel</Button>
            <Button type="submit" className="flex-1" disabled={processing}>
              {processing ? 'Creating...' : 'Create User'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
