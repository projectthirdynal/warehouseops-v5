import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { PageProps } from '@/types';
import {
  User, Bell, Shield, Users, Save, Key,
  CheckCircle,
  Activity, Mail,
  Globe, Smartphone, ChevronRight,
  Sliders, ShieldCheck, Plug,
  CheckSquare, Square, Clock,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import UserManagementSection from '@/components/admin/UserManagementSection';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

/* ─── Types ─── */
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

interface Permission {
  id: number;
  section: string;
  action: string;
  label: string;
  description: string;
}

interface ActivityLogItem {
  id: number;
  user_name: string;
  action: string;
  target: string;
  details: Record<string, unknown> | null;
  created_at: string;
}

interface Integration {
  name: string;
  icon: string;
  status: 'connected' | 'disconnected';
  description: string;
}

interface Props {
  settings: Record<string, string | boolean | number>;
  user: UserData;
  users?: ManagedUser[];
  roles?: { value: string; label: string }[];
  permissions?: Permission[];
  role_permissions?: Record<string, number>;
  activity_logs?: ActivityLogItem[];
  system_settings?: Record<string, string>;
  integrations?: Integration[];
}

/* ─── Helpers ─── */
function SuccessBanner({ message }: { message: string }) {
  return (
    <div className="flex items-center gap-2 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-700 dark:bg-green-950 dark:border-green-800 dark:text-green-300">
      <CheckCircle className="h-4 w-4 shrink-0" />
      {message}
    </div>
  );
}

const ROLE_OPTIONS = [
  { value: 'superadmin', label: 'IT Administrator' },
  { value: 'admin', label: 'Admin / Manager' },
  { value: 'supervisor', label: 'Supervisor' },
  { value: 'finance', label: 'Finance' },
  { value: 'accounting', label: 'Accounting' },
  { value: 'warehouse', label: 'Warehouse Staff' },
  { value: 'agent', label: 'Sales Agent' },
];


/* ─── Profile Section ─── */
function ProfileSection({ user }: { user: UserData }) {
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
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader><CardTitle>Personal Information</CardTitle><CardDescription>Update your personal details</CardDescription></CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-4">
            {recentlySuccessful && <SuccessBanner message="Profile updated successfully." />}
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2"><label className="text-sm font-medium">Full Name</label><Input value={data.name} onChange={e => setData('name', e.target.value)} /></div>
              <div className="space-y-2"><label className="text-sm font-medium">Email</label><Input type="email" value={data.email} onChange={e => setData('email', e.target.value)} /></div>
              <div className="space-y-2"><label className="text-sm font-medium">Phone</label><Input value={data.phone ?? ''} onChange={e => setData('phone', e.target.value)} /></div>
              <div className="space-y-2"><label className="text-sm font-medium">Department</label><Input value={ROLE_OPTIONS.find(r => r.value === user.role)?.label ?? user.role} disabled /></div>
            </div>
            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            <div className="flex justify-end"><Button type="submit" disabled={processing}><Save className="mr-2 h-4 w-4" />Save Changes</Button></div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Security Section ─── */
function SecuritySection() {
  const pwd = useForm({ current_password: '', password: '', password_confirmation: '' });
  const submitPwd = (e: React.FormEvent) => { e.preventDefault(); pwd.patch('/settings/password'); };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader><CardTitle className="flex items-center gap-2"><Key className="h-5 w-5" />Change Password</CardTitle><CardDescription>Update your account password</CardDescription></CardHeader>
        <CardContent>
          <form onSubmit={submitPwd} className="space-y-4">
            {pwd.recentlySuccessful && <SuccessBanner message="Password updated successfully." />}
            <div className="space-y-2"><label className="text-sm font-medium">Current Password</label><Input type="password" value={pwd.data.current_password} onChange={e => pwd.setData('current_password', e.target.value)} /></div>
            <div className="space-y-2"><label className="text-sm font-medium">New Password</label><Input type="password" value={pwd.data.password} onChange={e => pwd.setData('password', e.target.value)} /></div>
            <div className="space-y-2"><label className="text-sm font-medium">Confirm Password</label><Input type="password" value={pwd.data.password_confirmation} onChange={e => pwd.setData('password_confirmation', e.target.value)} /></div>
            {pwd.errors.current_password && <p className="text-sm text-destructive">{pwd.errors.current_password}</p>}
            {pwd.errors.password && <p className="text-sm text-destructive">{pwd.errors.password}</p>}
            <div className="flex justify-end"><Button type="submit" disabled={pwd.processing}><Key className="mr-2 h-4 w-4" />Update Password</Button></div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle className="flex items-center gap-2"><ShieldCheck className="h-5 w-5" />Password Policy</CardTitle><CardDescription>Current security requirements</CardDescription></CardHeader>
        <CardContent className="space-y-3">
          {[
            { label: 'Minimum 8 characters', ok: true },
            { label: 'Uppercase and lowercase letters', ok: true },
            { label: 'At least one number', ok: true },
            { label: 'At least one special character', ok: false },
          ].map(rule => (
            <div key={rule.label} className="flex items-center justify-between">
              <span className="text-sm">{rule.label}</span>
              <Badge variant={rule.ok ? 'default' : 'outline'} className={rule.ok ? 'bg-green-100 text-green-700' : 'text-muted-foreground'}>
                {rule.ok ? 'Required' : 'Recommended'}
              </Badge>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Roles & Permissions Section ─── */
function RolesPermissionsSection({ roles = [], permissions = [], rolePermissions = {} }: {
  roles: { value: string; label: string }[];
  permissions: Permission[];
  rolePermissions: Record<string, number>;
}) {
  const [selectedRole, setSelectedRole] = useState(roles[0]?.value ?? 'admin');
  const [localPerms, setLocalPerms] = useState<Record<string, boolean>>({});

  const sections = [...new Set(permissions.map(p => p.section))];

  const isChecked = (permId: number) => {
    const key = `${selectedRole}_${permId}`;
    if (localPerms[key] !== undefined) return localPerms[key];
    return !!rolePermissions[`${selectedRole}_${permId}`];
  };

  const toggle = (permId: number) => {
    const key = `${selectedRole}_${permId}`;
    setLocalPerms(prev => ({ ...prev, [key]: !isChecked(permId) }));
  };

  const save = () => {
    const selectedIds = permissions.filter(p => isChecked(p.id)).map(p => p.id);
    router.post('/settings/roles/permissions', { role: selectedRole, permissions: selectedIds });
  };

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="flex items-center gap-2"><Shield className="h-5 w-5" />Permission Matrix</CardTitle>
              <CardDescription>Configure access control for each role</CardDescription>
            </div>
            <Select value={selectedRole} onValueChange={setSelectedRole}>
              <SelectTrigger className="w-[180px]"><SelectValue /></SelectTrigger>
              <SelectContent>{roles.map(r => <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>)}</SelectContent>
            </Select>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {sections.map(section => (
            <div key={section} className="space-y-2">
              <h4 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">{section}</h4>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {permissions.filter(p => p.section === section).map(perm => (
                  <label key={perm.id} className="flex items-start gap-2 rounded-lg border p-3 cursor-pointer hover:bg-muted/50 transition-colors">
                    <div className="mt-0.5">
                      {isChecked(perm.id)
                        ? <CheckSquare className="h-4 w-4 text-primary" />
                        : <Square className="h-4 w-4 text-muted-foreground" />}
                    </div>
                    <input type="checkbox" className="sr-only" checked={isChecked(perm.id)} onChange={() => toggle(perm.id)} />
                    <div className="min-w-0">
                      <div className="text-sm font-medium">{perm.label}</div>
                      <div className="text-xs text-muted-foreground">{perm.description}</div>
                    </div>
                  </label>
                ))}
              </div>
            </div>
          ))}
          <div className="flex justify-end pt-2">
            <Button onClick={save}><Save className="mr-2 h-4 w-4" />Save Changes</Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Notifications Section ─── */
function NotificationsSection() {
  const [emailNotifs, setEmailNotifs] = useState({
    new_lead: true,
    lead_updated: true,
    qc_required: false,
    announcements: true,
  });
  const [smsNotifs, setSmsNotifs] = useState({
    urgent: true,
    daily_summary: false,
  });

  const toggle = (type: 'email' | 'sms', key: string) => {
    if (type === 'email') setEmailNotifs(prev => ({ ...prev, [key]: !prev[key as keyof typeof prev] }));
    else setSmsNotifs(prev => ({ ...prev, [key]: !prev[key as keyof typeof prev] }));
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader><CardTitle className="flex items-center gap-2"><Mail className="h-5 w-5" />Email Notifications</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          {[
            { key: 'new_lead', label: 'New Lead Assigned', desc: 'Receive an email when a new lead is assigned' },
            { key: 'lead_updated', label: 'Lead Updated', desc: 'Receive an email when a lead is updated' },
            { key: 'qc_required', label: 'QC Review Required', desc: 'Receive an email when a waybill needs QC' },
            { key: 'announcements', label: 'System Announcements', desc: 'Receive important system announcements' },
          ].map(n => (
            <div key={n.key} className="flex items-center justify-between py-2">
              <div>
                <div className="text-sm font-medium">{n.label}</div>
                <div className="text-xs text-muted-foreground">{n.desc}</div>
              </div>
              <Switch checked={!!emailNotifs[n.key as keyof typeof emailNotifs]} onCheckedChange={() => toggle('email', n.key)} />
            </div>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader><CardTitle className="flex items-center gap-2"><Smartphone className="h-5 w-5" />SMS Notifications</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          {[
            { key: 'urgent', label: 'Urgent Alerts', desc: 'Receive SMS for critical system alerts' },
            { key: 'daily_summary', label: 'Daily Summary', desc: 'Receive a daily summary of activities' },
          ].map(n => (
            <div key={n.key} className="flex items-center justify-between py-2">
              <div>
                <div className="text-sm font-medium">{n.label}</div>
                <div className="text-xs text-muted-foreground">{n.desc}</div>
              </div>
              <Switch checked={!!smsNotifs[n.key as keyof typeof smsNotifs]} onCheckedChange={() => toggle('sms', n.key)} />
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── System Section ─── */
function SystemSection({ system_settings = {} }: { system_settings?: Record<string, string> }) {
  const { data, setData, patch, processing, recentlySuccessful } = useForm({
    company_name: system_settings.company_name ?? 'TECS Warehouse Operations',
    timezone: system_settings.timezone ?? 'Asia/Manila',
    date_format: system_settings.date_format ?? 'MM/DD/YYYY',
    time_format: system_settings.time_format ?? '12 Hour (AM/PM)',
    currency: system_settings.currency ?? 'PHP - Philippine Peso',
    language: system_settings.language ?? 'en',
  });

  const submit = (e: React.FormEvent) => { e.preventDefault(); patch('/settings/system'); };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader><CardTitle className="flex items-center gap-2"><Globe className="h-5 w-5" />General Settings</CardTitle><CardDescription>Configure system-wide preferences</CardDescription></CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-4">
            {recentlySuccessful && <SuccessBanner message="System settings saved." />}
            <div className="space-y-2"><label className="text-sm font-medium">Company Name</label><Input value={data.company_name} onChange={e => setData('company_name', e.target.value)} /></div>
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2"><label className="text-sm font-medium">Timezone</label>
                <Select value={data.timezone} onValueChange={v => setData('timezone', v)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="Asia/Manila">Asia/Manila (GMT+08)</SelectItem><SelectItem value="Asia/Singapore">Asia/Singapore (GMT+08)</SelectItem></SelectContent>
                </Select>
              </div>
              <div className="space-y-2"><label className="text-sm font-medium">Date Format</label>
                <Select value={data.date_format} onValueChange={v => setData('date_format', v)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="MM/DD/YYYY">MM/DD/YYYY</SelectItem><SelectItem value="DD/MM/YYYY">DD/MM/YYYY</SelectItem><SelectItem value="YYYY-MM-DD">YYYY-MM-DD</SelectItem></SelectContent>
                </Select>
              </div>
              <div className="space-y-2"><label className="text-sm font-medium">Time Format</label>
                <Select value={data.time_format} onValueChange={v => setData('time_format', v)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="12 Hour (AM/PM)">12 Hour (AM/PM)</SelectItem><SelectItem value="24 Hour">24 Hour</SelectItem></SelectContent>
                </Select>
              </div>
              <div className="space-y-2"><label className="text-sm font-medium">Currency</label>
                <Select value={data.currency} onValueChange={v => setData('currency', v)}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent><SelectItem value="PHP - Philippine Peso">PHP - Philippine Peso</SelectItem><SelectItem value="USD - US Dollar">USD - US Dollar</SelectItem></SelectContent>
                </Select>
              </div>
            </div>
            <div className="flex justify-end"><Button type="submit" disabled={processing}><Save className="mr-2 h-4 w-4" />Save Changes</Button></div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Integrations Section ─── */
function IntegrationsSection({ integrations = [] }: { integrations?: Integration[] }) {
  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {integrations.map(intg => (
          <Card key={intg.name} className="relative overflow-hidden">
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                    <Plug className="h-5 w-5 text-primary" />
                  </div>
                  <div>
                    <div className="font-medium">{intg.name}</div>
                    <div className="text-xs text-muted-foreground">{intg.description}</div>
                  </div>
                </div>
                <Badge variant={intg.status === 'connected' ? 'default' : 'outline'} className={intg.status === 'connected' ? 'bg-green-100 text-green-700' : ''}>
                  {intg.status === 'connected' ? 'Connected' : 'Not Connected'}
                </Badge>
              </div>
              <div className="mt-4 flex justify-end">
                <Button variant="outline" size="sm">{intg.status === 'connected' ? 'Manage' : 'Connect'}</Button>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

/* ─── Activity Log Section ─── */
function ActivityLogSection({ logs = [] }: { logs?: ActivityLogItem[] }) {
  const actionColors: Record<string, string> = {
    create_user: 'bg-blue-100 text-blue-700',
    update_user: 'bg-amber-100 text-amber-700',
    toggle_user: 'bg-purple-100 text-purple-700',
    delete_user: 'bg-red-100 text-red-700',
    reset_password: 'bg-orange-100 text-orange-700',
    update_role_permissions: 'bg-cyan-100 text-cyan-700',
    update_system_settings: 'bg-green-100 text-green-700',
    update_profile: 'bg-gray-100 text-gray-700',
    update_password: 'bg-gray-100 text-gray-700',
  };

  return (
    <Card>
      <CardHeader><CardTitle className="flex items-center gap-2"><Clock className="h-5 w-5" />Recent Activity</CardTitle></CardHeader>
      <CardContent>
        <ScrollArea className="h-[400px]">
          <div className="space-y-3">
            {logs.length === 0 ? (
              <div className="text-center py-8 text-muted-foreground">No recent activity</div>
            ) : logs.map(log => (
              <div key={log.id} className="flex items-start gap-3 rounded-lg border p-3">
                <div className={cn('mt-0.5 rounded-full p-1.5', actionColors[log.action] ?? 'bg-gray-100 text-gray-700')}>
                  <Activity className="h-3.5 w-3.5" />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">{log.user_name}</span>
                    <span className="text-xs text-muted-foreground">{log.action.replace(/_/g, ' ')}</span>
                  </div>
                  <div className="text-xs text-muted-foreground mt-0.5">
                    {log.target} · {new Date(log.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}
                  </div>
                </div>
              </div>
            ))}
          </div>
        </ScrollArea>
      </CardContent>
    </Card>
  );
}

/* ─── Main Settings Page ─── */
export default function SettingsPage({
  user,
  users = [],
  roles = [],
  permissions = [],
  role_permissions = {},
  activity_logs = [],
  system_settings = {},
  integrations = [],
}: Props) {
  const { auth } = usePage<PageProps>().props;
  const [activeTab, setActiveTab] = useState('profile');
  const [_modalOpen, setModalOpen] = useState(false);
  const [_editUser, setEditUser] = useState<ManagedUser | null>(null);
  const [_resetPasswordUser, setResetPasswordUser] = useState<ManagedUser | null>(null);

  const isAdmin = ['superadmin', 'admin'].includes(auth?.user?.role ?? 'agent');

  const navItems = [
    { id: 'profile', label: 'Profile', icon: User, desc: 'Personal information' },
    { id: 'security', label: 'Security', icon: Shield, desc: 'Password & policy' },
    ...(isAdmin ? [
      { id: 'users', label: 'User Management', icon: Users, desc: 'Users & access' },
      { id: 'roles', label: 'Roles & Permissions', icon: ShieldCheck, desc: 'Access control' },
      { id: 'activity', label: 'Activity Log', icon: Clock, desc: 'Audit trail' },
    ] : []),
    { id: 'notifications', label: 'Notifications', icon: Bell, desc: 'Email & SMS' },
    { id: 'system', label: 'System', icon: Sliders, desc: 'General settings' },
    { id: 'integrations', label: 'Integrations', icon: Plug, desc: 'Connected apps' },
  ];

  return (
    <AppLayout>
      <Head title="Settings" />
      <div className="flex h-[calc(100vh-4rem)] overflow-hidden">
        {/* Sidebar */}
        <aside className="w-64 shrink-0 border-r bg-sidebar hidden md:block">
          <div className="p-4">
            <h2 className="text-lg font-semibold tracking-tight">Settings</h2>
            <p className="text-xs text-muted-foreground">Manage your account and application preferences</p>
          </div>
          <ScrollArea className="h-[calc(100%-5rem)]">
            <nav className="space-y-0.5 p-2">
              {navItems.map(item => {
                const Icon = item.icon;
                const active = activeTab === item.id;
                return (
                  <button
                    key={item.id}
                    onClick={() => setActiveTab(item.id)}
                    className={cn(
                      'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition-colors',
                      active ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium' : 'text-sidebar-foreground hover:bg-sidebar-accent/50'
                    )}
                  >
                    <Icon className="h-4 w-4 shrink-0" />
                    <div className="min-w-0">
                      <div className="truncate">{item.label}</div>
                      <div className="text-[10px] text-muted-foreground truncate">{item.desc}</div>
                    </div>
                    {active && <ChevronRight className="ml-auto h-4 w-4 shrink-0 opacity-50" />}
                  </button>
                );
              })}
            </nav>
          </ScrollArea>
        </aside>

        {/* Mobile dropdown */}
        <div className="md:hidden p-4 border-b w-full">
          <Select value={activeTab} onValueChange={setActiveTab}>
            <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
            <SelectContent>
              {navItems.map(item => <SelectItem key={item.id} value={item.id}>{item.label}</SelectItem>)}
            </SelectContent>
          </Select>
        </div>

        {/* Main content */}
        <main className="flex-1 overflow-auto p-6">
          <div className="max-w-5xl mx-auto">
            <div className="mb-6">
              <h1 className="text-2xl font-bold tracking-tight">
                {navItems.find(n => n.id === activeTab)?.label ?? 'Settings'}
              </h1>
              <p className="text-sm text-muted-foreground">
                {navItems.find(n => n.id === activeTab)?.desc ?? ''}
              </p>
            </div>

            {activeTab === 'profile' && <ProfileSection user={user} />}
            {activeTab === 'security' && <SecuritySection />}
            {activeTab === 'users' && isAdmin && (
              <>
                <UserManagementSection
                  users={users}
                  currentUserId={user.id}
                  onCreate={() => { setEditUser(null); setModalOpen(true); }}
                  onEdit={(u) => { setEditUser(u); setModalOpen(true); }}
                  onResetPassword={(u) => { if (confirm(`Reset password for "${u.name}"?`)) setResetPasswordUser(u); }}
                />
                {/* Modals would go here */}
              </>
            )}
            {activeTab === 'roles' && isAdmin && (
              <RolesPermissionsSection roles={roles} permissions={permissions} rolePermissions={role_permissions} />
            )}
            {activeTab === 'activity' && isAdmin && (
              <ActivityLogSection logs={activity_logs} />
            )}
            {activeTab === 'notifications' && <NotificationsSection />}
            {activeTab === 'system' && <SystemSection system_settings={system_settings} />}
            {activeTab === 'integrations' && <IntegrationsSection integrations={integrations} />}
          </div>
        </main>
      </div>
    </AppLayout>
  );
}
