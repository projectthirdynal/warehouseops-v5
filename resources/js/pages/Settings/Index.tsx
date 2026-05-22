import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  User,
  Bell,
  Shield,
  Database,
  Truck,
  Users,
  Palette,
  Save,
  Key,
  CheckCircle,
  Plus,
  Pencil,
  Trash2,
  ToggleLeft,
  ToggleRight,
  X,
  UserCog,
  RotateCcw,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface UserData {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: string;
  theme: string;
  language: string;
  timezone: string;
}

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
  settings: Record<string, string | boolean | number>;
  user: UserData;
  can_manage_users?: boolean;
  users?: ManagedUser[];
}

function SuccessBanner({ message }: { message: string }) {
  return (
    <div className="flex items-center gap-2 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700 dark:bg-green-950 dark:border-green-800 dark:text-green-300">
      <CheckCircle className="h-4 w-4 shrink-0" />
      {message}
    </div>
  );
}

function ProfileTab({ user }: { user: UserData }) {
  const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
    name: user.name ?? '',
    email: user.email ?? '',
    phone: user.phone ?? '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/profile');
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Profile Settings</CardTitle>
        <CardDescription>Update your personal information</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-6">
          {recentlySuccessful && <SuccessBanner message="Profile updated successfully." />}

          <div className="grid gap-4 md:grid-cols-2">
            <div className="space-y-2">
              <label className="text-sm font-medium">Full Name</label>
              <input
                type="text"
                value={data.name}
                onChange={e => setData('name', e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">Email</label>
              <input
                type="email"
                value={data.email}
                onChange={e => setData('email', e.target.value)}
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
            </div>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Phone Number</label>
            <input
              type="tel"
              value={data.phone}
              onChange={e => setData('phone', e.target.value)}
              placeholder="+63 9XX XXX XXXX"
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.phone && <p className="text-xs text-destructive">{errors.phone}</p>}
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Role</label>
            <div className="flex items-center gap-2">
              <Badge>{user.role || 'Agent'}</Badge>
              <span className="text-sm text-muted-foreground">Contact admin to change role</span>
            </div>
          </div>

          <Button type="submit" disabled={processing}>
            <Save className="mr-2 h-4 w-4" />
            {processing ? 'Saving…' : 'Save Changes'}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function SecurityTab() {
  const { data, setData, patch, processing, errors, recentlySuccessful, reset } = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/password', {
      onSuccess: () => reset(),
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Security Settings</CardTitle>
        <CardDescription>Manage your password and security preferences</CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <form onSubmit={submit} className="space-y-4">
          <h3 className="font-medium flex items-center gap-2">
            <Key className="h-4 w-4" /> Change Password
          </h3>

          {recentlySuccessful && <SuccessBanner message="Password updated successfully." />}

          <div className="space-y-2">
            <label className="text-sm font-medium">Current Password</label>
            <input
              type="password"
              value={data.current_password}
              onChange={e => setData('current_password', e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.current_password && <p className="text-xs text-destructive">{errors.current_password}</p>}
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">New Password</label>
            <input
              type="password"
              value={data.password}
              onChange={e => setData('password', e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Confirm New Password</label>
            <input
              type="password"
              value={data.password_confirmation}
              onChange={e => setData('password_confirmation', e.target.value)}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
          </div>

          <Button type="submit" disabled={processing}>
            {processing ? 'Updating…' : 'Update Password'}
          </Button>
        </form>

        <div className="border-t pt-6">
          <h3 className="font-medium mb-4">Active Sessions</h3>
          <div className="space-y-3">
            <div className="flex items-center justify-between rounded-lg border p-3">
              <div>
                <div className="font-medium">Current Session</div>
                <div className="text-sm text-muted-foreground">This device</div>
              </div>
              <Badge variant="default">Active</Badge>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function AppearanceTab({ user }: { user: UserData }) {
  const { data, setData, patch, processing, recentlySuccessful } = useForm({
    theme: user.theme ?? 'light',
    language: user.language ?? 'en',
    timezone: user.timezone ?? 'Asia/Manila',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/appearance', {
      onSuccess: () => {
        applyTheme(data.theme);
      },
    });
  };

  const applyTheme = (theme: string) => {
    const html = document.documentElement;
    if (theme === 'dark') {
      html.classList.add('dark');
    } else if (theme === 'light') {
      html.classList.remove('dark');
    } else {
      // system
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      html.classList.toggle('dark', prefersDark);
    }
  };

  const handleThemeChange = (value: string) => {
    setData('theme', value);
    applyTheme(value);
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Appearance</CardTitle>
        <CardDescription>Customize the look and feel of the application</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="space-y-6">
          {recentlySuccessful && <SuccessBanner message="Preferences saved." />}

          <div className="space-y-2">
            <label className="text-sm font-medium">Theme</label>
            <Select value={data.theme} onValueChange={handleThemeChange}>
              <SelectTrigger className="w-full md:w-[200px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="light">Light</SelectItem>
                <SelectItem value="dark">Dark</SelectItem>
                <SelectItem value="system">System</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Language</label>
            <Select value={data.language} onValueChange={v => setData('language', v)}>
              <SelectTrigger className="w-full md:w-[200px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="en">English</SelectItem>
                <SelectItem value="tl">Filipino (Tagalog)</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <label className="text-sm font-medium">Timezone</label>
            <Select value={data.timezone} onValueChange={v => setData('timezone', v)}>
              <SelectTrigger className="w-full md:w-[300px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="Asia/Manila">Asia/Manila (GMT+8)</SelectItem>
                <SelectItem value="UTC">UTC</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <Button type="submit" disabled={processing}>
            <Save className="mr-2 h-4 w-4" />
            {processing ? 'Saving…' : 'Save Preferences'}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

const ROLE_OPTIONS = [
  { value: 'superadmin',  label: 'IT Administrator' },
  { value: 'admin',       label: 'Admin / Manager' },
  { value: 'supervisor',  label: 'Supervisor' },
  { value: 'finance',     label: 'Finance' },
  { value: 'accounting',  label: 'Accounting' },
  { value: 'warehouse',   label: 'Warehouse Staff' },
  { value: 'agent',       label: 'Sales Agent' },
];

const ROLE_COLORS: Record<string, string> = {
  superadmin: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
  admin:      'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
  supervisor: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-300',
  finance:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
  accounting: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-300',
  warehouse:  'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
  agent:      'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
};

function RoleBadge({ role }: { role: string }) {
  const label = ROLE_OPTIONS.find(r => r.value === role)?.label ?? role;
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${ROLE_COLORS[role] ?? 'bg-gray-100 text-gray-700'}`}>
      {label}
    </span>
  );
}

function UserFormModal({
  open,
  onClose,
  editUser,
}: {
  open: boolean;
  onClose: () => void;
  editUser: ManagedUser | null;
}) {
  const isEdit = !!editUser;
  const { data, setData, post, patch, processing, errors, reset } = useForm({
    name:     editUser?.name     ?? '',
    email:    editUser?.email    ?? '',
    phone:    editUser?.phone    ?? '',
    role:     editUser?.role     ?? 'agent',
    password: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (isEdit) {
      patch(`/settings/users/${editUser!.id}`, {
        onSuccess: () => { reset(); onClose(); },
      });
    } else {
      post('/settings/users', {
        onSuccess: () => { reset(); onClose(); },
      });
    }
  };

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="w-full max-w-md rounded-xl border bg-background shadow-2xl">
        <div className="flex items-center justify-between border-b px-6 py-4">
          <h2 className="text-lg font-semibold">
            {isEdit ? 'Edit User' : 'Create New User'}
          </h2>
          <Button variant="ghost" size="icon" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>

        <form onSubmit={submit} className="space-y-4 px-6 py-5">
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Full Name *</label>
              <input
                type="text"
                value={data.name}
                onChange={e => setData('name', e.target.value)}
                placeholder="Juan dela Cruz"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              {errors.name && <p className="text-xs text-destructive">{errors.name}</p>}
            </div>
            <div className="space-y-1.5">
              <label className="text-sm font-medium">Phone</label>
              <input
                type="tel"
                value={data.phone}
                onChange={e => setData('phone', e.target.value)}
                placeholder="+63 9XX XXX XXXX"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
            </div>
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium">Email *</label>
            <input
              type="email"
              value={data.email}
              onChange={e => setData('email', e.target.value)}
              placeholder="user@company.com"
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium">Role *</label>
            <Select value={data.role} onValueChange={v => setData('role', v)}>
              <SelectTrigger className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROLE_OPTIONS.map(r => (
                  <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.role && <p className="text-xs text-destructive">{errors.role}</p>}
          </div>

          <div className="space-y-1.5">
            <label className="text-sm font-medium">
              {isEdit ? 'New Password' : 'Password *'}
              {isEdit && <span className="text-xs text-muted-foreground ml-1">(leave blank to keep current)</span>}
            </label>
            <input
              type="password"
              value={data.password}
              onChange={e => setData('password', e.target.value)}
              placeholder={isEdit ? '••••••••' : 'Min. 8 characters'}
              className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
          </div>

          <div className="flex justify-end gap-2 pt-2 border-t">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={processing}>
              {processing ? 'Saving…' : isEdit ? 'Save Changes' : 'Create User'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
}

function PasswordResetModal({
  open,
  onClose,
  user,
}: {
  open: boolean;
  onClose: () => void;
  user: ManagedUser | null;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    password: '',
    password_confirmation: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!user) return;
    
    post(`/settings/users/${user.id}/reset-password`, {
      onSuccess: () => {
        reset();
        onClose();
      },
    });
  };

  if (!open || !user) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="w-full max-w-md rounded-xl border bg-background shadow-2xl">
        <div className="flex items-center justify-between border-b px-6 py-4">
          <h2 className="text-lg font-semibold">Reset Password</h2>
          <Button variant="ghost" size="icon" onClick={onClose}><X className="h-4 w-4" /></Button>
        </div>

        <div className="px-6 py-4">
          <div className="mb-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-700">
            <strong>Resetting password for:</strong>
            <div className="font-medium">{user.name}</div>
            <div className="text-xs">{user.email}</div>
            <div className="mt-2 text-xs">User will need to use the new password to login.</div>
          </div>

          <form onSubmit={submit} className="space-y-4">
            <div className="space-y-1.5">
              <label className="text-sm font-medium">New Password *</label>
              <input
                type="password"
                value={data.password}
                onChange={e => setData('password', e.target.value)}
                placeholder="Min. 8 characters"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                required
              />
              {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
            </div>

            <div className="space-y-1.5">
              <label className="text-sm font-medium">Confirm New Password *</label>
              <input
                type="password"
                value={data.password_confirmation}
                onChange={e => setData('password_confirmation', e.target.value)}
                placeholder="Confirm new password"
                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                required
              />
              {errors.password_confirmation && <p className="text-xs text-destructive">{errors.password_confirmation}</p>}
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t">
              <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
              <Button type="submit" disabled={processing}>
                {processing ? 'Resetting…' : 'Reset Password'}
              </Button>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
}

function UserManagementTab({ users = [], currentUserId }: { users: ManagedUser[]; currentUserId: number }) {
  const [modalOpen, setModalOpen] = useState(false);
  const [editUser, setEditUser] = useState<ManagedUser | null>(null);
  const [resetPasswordUser, setResetPasswordUser] = useState<ManagedUser | null>(null);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');

  const filtered = users.filter(u => {
    const matchesSearch = u.name.toLowerCase().includes(search.toLowerCase()) ||
      u.email.toLowerCase().includes(search.toLowerCase());
    const matchesRole = roleFilter === 'all' || u.role === roleFilter;
    return matchesSearch && matchesRole;
  });

  const openCreate = () => { setEditUser(null); setModalOpen(true); };
  const openEdit   = (u: ManagedUser) => { setEditUser(u); setModalOpen(true); };

  const handleToggle = (u: ManagedUser) => {
    router.post(`/settings/users/${u.id}/toggle`, {}, { preserveScroll: true });
  };

  const handleDelete = (u: ManagedUser) => {
    if (!confirm(`Delete user "${u.name}"? This cannot be undone.`)) return;
    router.delete(`/settings/users/${u.id}`, { preserveScroll: true });
  };

  const handleResetPassword = (u: ManagedUser) => {
    if (!confirm(`Reset password for "${u.name}"? They will need to use the new password to login.`)) return;
    setResetPasswordUser(u);
  };

  const roleCounts = ROLE_OPTIONS.map(r => ({
    ...r,
    count: users.filter(u => u.role === r.value).length,
  })).filter(r => r.count > 0);

  return (
    <>
      <UserFormModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        editUser={editUser}
      />

      <PasswordResetModal
        open={!!resetPasswordUser}
        onClose={() => setResetPasswordUser(null)}
        user={resetPasswordUser}
      />

      <div className="space-y-4">
        {/* Summary cards */}
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div className="rounded-lg border bg-card p-3 text-center">
            <div className="text-2xl font-bold">{users.length}</div>
            <div className="text-xs text-muted-foreground mt-0.5">Total Users</div>
          </div>
          <div className="rounded-lg border bg-card p-3 text-center">
            <div className="text-2xl font-bold text-green-600">{users.filter(u => u.is_active).length}</div>
            <div className="text-xs text-muted-foreground mt-0.5">Active</div>
          </div>
          <div className="rounded-lg border bg-card p-3 text-center">
            <div className="text-2xl font-bold text-red-500">{users.filter(u => !u.is_active).length}</div>
            <div className="text-xs text-muted-foreground mt-0.5">Inactive</div>
          </div>
          <div className="rounded-lg border bg-card p-3 text-center">
            <div className="text-2xl font-bold text-blue-600">{roleCounts.length}</div>
            <div className="text-xs text-muted-foreground mt-0.5">Roles in Use</div>
          </div>
        </div>

        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <UserCog className="h-5 w-5" />
                  User Management
                </CardTitle>
                <CardDescription>Create, edit, activate or deactivate system users</CardDescription>
              </div>
              <Button onClick={openCreate} size="sm">
                <Plus className="mr-1.5 h-4 w-4" /> New User
              </Button>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Filters */}
            <div className="flex flex-col gap-2 sm:flex-row">
              <input
                type="text"
                placeholder="Search by name or email…"
                value={search}
                onChange={e => setSearch(e.target.value)}
                className="flex h-9 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              />
              <Select value={roleFilter} onValueChange={setRoleFilter}>
                <SelectTrigger className="h-9 w-full sm:w-[180px]">
                  <SelectValue placeholder="All Roles" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Roles</SelectItem>
                  {ROLE_OPTIONS.map(r => (
                    <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* User table */}
            <div className="rounded-lg border overflow-hidden">
              <table className="w-full text-sm">
                <thead className="bg-muted/50">
                  <tr>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground">User</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground hidden md:table-cell">Role</th>
                    <th className="px-4 py-2.5 text-left font-medium text-muted-foreground hidden lg:table-cell">Last Login</th>
                    <th className="px-4 py-2.5 text-center font-medium text-muted-foreground">Status</th>
                    <th className="px-4 py-2.5 text-right font-medium text-muted-foreground">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {filtered.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                        No users found.
                      </td>
                    </tr>
                  ) : filtered.map(u => (
                    <tr key={u.id} className="hover:bg-muted/30 transition-colors">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-xs font-semibold uppercase">
                            {u.name.split(' ').map(n => n[0]).join('').slice(0, 2)}
                          </div>
                          <div className="min-w-0">
                            <div className="font-medium truncate">{u.name}</div>
                            <div className="text-xs text-muted-foreground truncate">{u.email}</div>
                            {u.phone && <div className="text-xs text-muted-foreground">{u.phone}</div>}
                          </div>
                        </div>
                        <div className="mt-1 md:hidden">
                          <RoleBadge role={u.role} />
                        </div>
                      </td>
                      <td className="px-4 py-3 hidden md:table-cell">
                        <RoleBadge role={u.role} />
                      </td>
                      <td className="px-4 py-3 hidden lg:table-cell text-muted-foreground text-xs">
                        {u.last_login_at
                          ? new Date(u.last_login_at).toLocaleDateString('en-PH', { dateStyle: 'medium' })
                          : <span className="italic">Never</span>}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${
                          u.is_active
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                            : 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400'
                        }`}>
                          <span className={`h-1.5 w-1.5 rounded-full ${u.is_active ? 'bg-green-500' : 'bg-red-500'}`} />
                          {u.is_active ? 'Active' : 'Inactive'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-1">
                          <Button
                            variant="ghost" size="icon" className="h-8 w-8"
                            title="Edit user"
                            onClick={() => openEdit(u)}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost" size="icon" className="h-8 w-8"
                            title="Reset password"
                            onClick={() => handleResetPassword(u)}
                          >
                            <RotateCcw className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost" size="icon" className="h-8 w-8"
                            title={u.is_active ? 'Deactivate' : 'Activate'}
                            onClick={() => handleToggle(u)}
                            disabled={u.id === currentUserId}
                          >
                            {u.is_active
                              ? <ToggleRight className="h-4 w-4 text-green-600" />
                              : <ToggleLeft className="h-4 w-4 text-muted-foreground" />}
                          </Button>
                          {u.id !== currentUserId && (
                            <Button
                              variant="ghost" size="icon" className="h-8 w-8 text-destructive hover:text-destructive"
                              title="Delete user"
                              onClick={() => handleDelete(u)}
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Role legend */}
            {roleCounts.length > 0 && (
              <div className="flex flex-wrap gap-2 pt-1">
                {roleCounts.map(r => (
                  <button
                    key={r.value}
                    onClick={() => setRoleFilter(roleFilter === r.value ? 'all' : r.value)}
                    className={`flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors ${
                      roleFilter === r.value ? 'border-primary bg-primary/10' : 'hover:bg-muted'
                    }`}
                  >
                    <span className={`h-2 w-2 rounded-full ${ROLE_COLORS[r.value]?.split(' ')[0]}`} />
                    {r.label}
                    <span className="font-semibold">{r.count}</span>
                  </button>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </>
  );
}

export default function SettingsIndex({ user, settings, can_manage_users = false, users = [] }: Props) {
  const [activeTab, setActiveTab] = useState('appearance');

  const tabs = [
    { id: 'profile',      label: 'Profile',       icon: User },
    { id: 'security',     label: 'Security',       icon: Shield },
    { id: 'appearance',   label: 'Appearance',     icon: Palette },
    { id: 'notifications',label: 'Notifications',  icon: Bell },
    { id: 'courier',      label: 'Courier Config', icon: Truck },
    { id: 'team',         label: 'Team Settings',  icon: Users },
    { id: 'system',       label: 'System',         icon: Database },
    ...(can_manage_users ? [{ id: 'users', label: 'User Management', icon: UserCog }] : []),
  ];

  return (
    <AppLayout>
      <Head title="Settings" />

      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
          <p className="text-muted-foreground">Manage your account and application preferences</p>
        </div>

        <div className="flex flex-col gap-6 lg:flex-row">
          {/* Sidebar */}
          <div className="w-full lg:w-64 space-y-1">
            {tabs.map((tab) => {
              const Icon = tab.icon;
              return (
                <button
                  key={tab.id}
                  onClick={() => setActiveTab(tab.id)}
                  className={`flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors ${
                    activeTab === tab.id
                      ? 'bg-primary text-primary-foreground'
                      : 'hover:bg-muted'
                  }`}
                >
                  <Icon className="h-4 w-4" />
                  {tab.label}
                </button>
              );
            })}
          </div>

          {/* Content */}
          <div className="flex-1">
            {activeTab === 'profile' && <ProfileTab user={user} />}

            {activeTab === 'appearance' && <AppearanceTab user={user} />}

            {activeTab === 'security' && <SecurityTab />}

            {activeTab === 'notifications' && (
              <Card>
                <CardHeader>
                  <CardTitle>Notification Preferences</CardTitle>
                  <CardDescription>Configure how you receive notifications</CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  {[
                    { id: 'new_lead', label: 'New Lead Assigned', description: 'Get notified when a new lead is assigned to you' },
                    { id: 'sale_approved', label: 'Sale Approved', description: 'Notification when your sale is approved by QC' },
                    { id: 'waybill_status', label: 'Waybill Status Updates', description: 'Track delivery status changes' },
                    { id: 'system_alerts', label: 'System Alerts', description: 'Important system notifications and updates' },
                  ].map((item) => (
                    <div key={item.id} className="flex items-center justify-between">
                      <div>
                        <div className="font-medium">{item.label}</div>
                        <div className="text-sm text-muted-foreground">{item.description}</div>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" className="sr-only peer" defaultChecked />
                        <div className="w-11 h-6 bg-muted rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                      </label>
                    </div>
                  ))}
                  <Button>
                    <Save className="mr-2 h-4 w-4" />
                    Save Preferences
                  </Button>
                </CardContent>
              </Card>
            )}

            {activeTab === 'courier' && (
              <Card>
                <CardHeader>
                  <CardTitle>Courier Configuration</CardTitle>
                  <CardDescription>Manage courier provider integrations and settings</CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  {[
                    { name: 'J&T Express', code: 'JNT', status: 'active' },
                    { name: 'Flash Express', code: 'FLASH', status: 'active' },
                    { name: 'Ninja Van', code: 'NINJA', status: 'inactive' },
                    { name: '2GO Express', code: '2GO', status: 'inactive' },
                  ].map((courier) => (
                    <div key={courier.code} className="flex items-center justify-between rounded-lg border p-4">
                      <div className="flex items-center gap-4">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                          <Truck className="h-5 w-5" />
                        </div>
                        <div>
                          <div className="font-medium">{courier.name}</div>
                          <div className="text-sm text-muted-foreground">Code: {courier.code}</div>
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <Badge variant={courier.status === 'active' ? 'default' : 'secondary'}>
                          {courier.status}
                        </Badge>
                        <Button variant="outline" size="sm">Configure</Button>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}

            {activeTab === 'team' && (
              <Card>
                <CardHeader>
                  <CardTitle>Team Settings</CardTitle>
                  <CardDescription>Configure team and agent settings</CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <label className="text-sm font-medium">Max Active Leads per Agent</label>
                      <input
                        type="number"
                        defaultValue={settings.max_active_leads as number}
                        className="flex h-10 w-full md:w-[200px] rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      />
                    </div>
                    <div className="space-y-2">
                      <label className="text-sm font-medium">Lead Recycle After (No Answer)</label>
                      <Select defaultValue={String(settings.recycle_attempts)}>
                        <SelectTrigger className="w-full md:w-[200px]">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="2">2 attempts</SelectItem>
                          <SelectItem value="3">3 attempts</SelectItem>
                          <SelectItem value="5">5 attempts</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <label className="text-sm font-medium">Callback Expiry</label>
                      <Select defaultValue={String(settings.callback_expiry_hours)}>
                        <SelectTrigger className="w-full md:w-[200px]">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="12">12 hours</SelectItem>
                          <SelectItem value="24">24 hours</SelectItem>
                          <SelectItem value="48">48 hours</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                  <Button>
                    <Save className="mr-2 h-4 w-4" />
                    Save Settings
                  </Button>
                </CardContent>
              </Card>
            )}

            {activeTab === 'system' && (
              <Card>
                <CardHeader>
                  <CardTitle>System Information</CardTitle>
                  <CardDescription>Application and system configuration</CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                  <div className="grid gap-4">
                    <div className="flex items-center justify-between py-2 border-b">
                      <span className="text-muted-foreground">Application Version</span>
                      <Badge variant="outline">v5.0.0</Badge>
                    </div>
                    <div className="flex items-center justify-between py-2 border-b">
                      <span className="text-muted-foreground">Environment</span>
                      <Badge variant="default">Production</Badge>
                    </div>
                    <div className="flex items-center justify-between py-2 border-b">
                      <span className="text-muted-foreground">Database</span>
                      <span className="font-mono text-sm">PostgreSQL 16</span>
                    </div>
                    <div className="flex items-center justify-between py-2 border-b">
                      <span className="text-muted-foreground">Cache</span>
                      <span className="font-mono text-sm">Redis 7</span>
                    </div>
                    <div className="flex items-center justify-between py-2">
                      <span className="text-muted-foreground">PHP Version</span>
                      <span className="font-mono text-sm">8.3</span>
                    </div>
                  </div>
                  <div className="flex gap-2">
                    <Button variant="outline">Clear Cache</Button>
                    <Button variant="outline">Run Migrations</Button>
                  </div>
                </CardContent>
              </Card>
            )}

            {activeTab === 'users' && can_manage_users && (
              <UserManagementTab users={users} currentUserId={user.id} />
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
