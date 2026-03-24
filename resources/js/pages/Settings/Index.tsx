import { Head, useForm } from '@inertiajs/react';
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

interface Props {
  settings: Record<string, string | boolean | number>;
  user: UserData;
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

export default function SettingsIndex({ user, settings }: Props) {
  const [activeTab, setActiveTab] = useState('appearance');

  const tabs = [
    { id: 'profile', label: 'Profile', icon: User },
    { id: 'notifications', label: 'Notifications', icon: Bell },
    { id: 'security', label: 'Security', icon: Shield },
    { id: 'appearance', label: 'Appearance', icon: Palette },
    { id: 'courier', label: 'Courier Config', icon: Truck },
    { id: 'team', label: 'Team Settings', icon: Users },
    { id: 'system', label: 'System', icon: Database },
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
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
