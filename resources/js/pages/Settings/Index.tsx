import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
  User,
  Bell,
  Shield,
  Save,
  Key,
  CheckCircle,
  XCircle,
  Loader2,
  Mail,
  Server,
  Printer,
  ScanLine,
  Globe,
  Smartphone,
  ChevronRight,
  Sliders,
  Plug,
  FlaskConical,
  Wifi,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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

interface Integration {
  name: string;
  icon: string;
  status: 'connected' | 'disconnected';
  description: string;
}

interface EmailSettings {
  mailer: string;
  host: string;
  port: string;
  encryption: string;
  username: string;
  password: string;
  from_address: string;
  from_name: string;
  is_configured: boolean;
}

interface PrinterSettings {
  enabled: boolean;
  type: string;
  ip_address: string;
  port: string;
  dpi: string;
  label_width_mm: string;
  label_height_mm: string;
  copies: string;
  printer_name: string;
}

interface ScannerSettings {
  enabled: boolean;
  mode: string;
  sound_enabled: boolean;
  auto_submit: boolean;
  beep_on_success: boolean;
  beep_on_error: boolean;
}

interface Props {
  settings: Record<string, string | boolean | number>;
  user: UserData;
  system_settings?: Record<string, string>;
  integrations?: Integration[];
  email_settings?: EmailSettings;
  printer_settings?: PrinterSettings;
  scanner_settings?: ScannerSettings;
}

/* ─── Helpers ─── */
function SuccessBanner({ message }: { message: string }) {
  return (
    <div className="flex items-center gap-2 rounded-md bg-success/5 border border-success/20 px-4 py-3 text-sm text-success dark:bg-success/20 dark:border-success/30 dark:text-success">
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
    <div className="space-y-4 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle>Personal Information</CardTitle>
          <CardDescription>Update your personal details</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-4">
            {recentlySuccessful && <SuccessBanner message="Profile updated successfully." />}
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <label className="text-sm font-medium">Full Name</label>
                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Email</label>
                <Input
                  type="email"
                  value={data.email}
                  onChange={(e) => setData('email', e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Phone</label>
                <Input
                  value={data.phone ?? ''}
                  onChange={(e) => setData('phone', e.target.value)}
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Department</label>
                <Input
                  value={ROLE_OPTIONS.find((r) => r.value === user.role)?.label ?? user.role}
                  disabled
                />
              </div>
            </div>
            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
            <div className="flex justify-end">
              <Button type="submit" disabled={processing}>
                <Save className="mr-1.5 h-4 w-4" />
                Save Changes
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Security Section ─── */
function SecuritySection() {
  const pwd = useForm({ current_password: '', password: '', password_confirmation: '' });
  const submitPwd = (e: React.FormEvent) => {
    e.preventDefault();
    pwd.patch('/settings/password');
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Key className="h-5 w-5" />
            Change Password
          </CardTitle>
          <CardDescription>Update your account password</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submitPwd} className="space-y-4">
            {pwd.recentlySuccessful && <SuccessBanner message="Password updated successfully." />}
            <div className="space-y-2">
              <label className="text-sm font-medium">Current Password</label>
              <Input
                type="password"
                value={pwd.data.current_password}
                onChange={(e) => pwd.setData('current_password', e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">New Password</label>
              <Input
                type="password"
                value={pwd.data.password}
                onChange={(e) => pwd.setData('password', e.target.value)}
              />
            </div>
            <div className="space-y-2">
              <label className="text-sm font-medium">Confirm Password</label>
              <Input
                type="password"
                value={pwd.data.password_confirmation}
                onChange={(e) => pwd.setData('password_confirmation', e.target.value)}
              />
            </div>
            {pwd.errors.current_password && (
              <p className="text-sm text-destructive">{pwd.errors.current_password}</p>
            )}
            {pwd.errors.password && (
              <p className="text-sm text-destructive">{pwd.errors.password}</p>
            )}
            <div className="flex justify-end">
              <Button type="submit" disabled={pwd.processing}>
                <Key className="mr-1.5 h-4 w-4" />
                Update Password
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Shield className="h-5 w-5" />
            Password Policy
          </CardTitle>
          <CardDescription>Current security requirements</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {[
            { label: 'Minimum 8 characters', ok: true },
            { label: 'Uppercase and lowercase letters', ok: true },
            { label: 'At least one number', ok: true },
            { label: 'At least one special character', ok: false },
          ].map((rule) => (
            <div key={rule.label} className="flex items-center justify-between">
              <span className="text-sm">{rule.label}</span>
              <Badge
                variant={rule.ok ? 'default' : 'outline'}
                className={rule.ok ? 'bg-success/10 text-success' : 'text-muted-foreground'}
              >
                {rule.ok ? 'Required' : 'Recommended'}
              </Badge>
            </div>
          ))}
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
    if (type === 'email')
      setEmailNotifs((prev) => ({ ...prev, [key]: !prev[key as keyof typeof prev] }));
    else setSmsNotifs((prev) => ({ ...prev, [key]: !prev[key as keyof typeof prev] }));
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Mail className="h-5 w-5" />
            Email Notifications
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {[
            {
              key: 'new_lead',
              label: 'New Lead Assigned',
              desc: 'Receive an email when a new lead is assigned',
            },
            {
              key: 'lead_updated',
              label: 'Lead Updated',
              desc: 'Receive an email when a lead is updated',
            },
            {
              key: 'qc_required',
              label: 'QC Review Required',
              desc: 'Receive an email when a waybill needs QC',
            },
            {
              key: 'announcements',
              label: 'System Announcements',
              desc: 'Receive important system announcements',
            },
          ].map((n) => (
            <div key={n.key} className="flex items-center justify-between py-2">
              <div>
                <div className="text-sm font-medium">{n.label}</div>
                <div className="text-xs text-muted-foreground">{n.desc}</div>
              </div>
              <Switch
                checked={!!emailNotifs[n.key as keyof typeof emailNotifs]}
                onCheckedChange={() => toggle('email', n.key)}
              />
            </div>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Smartphone className="h-5 w-5" />
            SMS Notifications
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {[
            {
              key: 'urgent',
              label: 'Urgent Alerts',
              desc: 'Receive SMS for critical system alerts',
            },
            {
              key: 'daily_summary',
              label: 'Daily Summary',
              desc: 'Receive a daily summary of activities',
            },
          ].map((n) => (
            <div key={n.key} className="flex items-center justify-between py-2">
              <div>
                <div className="text-sm font-medium">{n.label}</div>
                <div className="text-xs text-muted-foreground">{n.desc}</div>
              </div>
              <Switch
                checked={!!smsNotifs[n.key as keyof typeof smsNotifs]}
                onCheckedChange={() => toggle('sms', n.key)}
              />
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

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/system');
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Globe className="h-5 w-5" />
            General Settings
          </CardTitle>
          <CardDescription>Configure system-wide preferences</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-4">
            {recentlySuccessful && <SuccessBanner message="System settings saved." />}
            <div className="space-y-2">
              <label className="text-sm font-medium">Company Name</label>
              <Input
                value={data.company_name}
                onChange={(e) => setData('company_name', e.target.value)}
              />
            </div>
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-2">
                <label className="text-sm font-medium">Timezone</label>
                <Select value={data.timezone} onValueChange={(v) => setData('timezone', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Asia/Manila">Asia/Manila (GMT+08)</SelectItem>
                    <SelectItem value="Asia/Singapore">Asia/Singapore (GMT+08)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Date Format</label>
                <Select value={data.date_format} onValueChange={(v) => setData('date_format', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="MM/DD/YYYY">MM/DD/YYYY</SelectItem>
                    <SelectItem value="DD/MM/YYYY">DD/MM/YYYY</SelectItem>
                    <SelectItem value="YYYY-MM-DD">YYYY-MM-DD</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Time Format</label>
                <Select value={data.time_format} onValueChange={(v) => setData('time_format', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="12 Hour (AM/PM)">12 Hour (AM/PM)</SelectItem>
                    <SelectItem value="24 Hour">24 Hour</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Currency</label>
                <Select value={data.currency} onValueChange={(v) => setData('currency', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="PHP - Philippine Peso">PHP - Philippine Peso</SelectItem>
                    <SelectItem value="USD - US Dollar">USD - US Dollar</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="flex justify-end">
              <Button type="submit" disabled={processing}>
                <Save className="mr-1.5 h-4 w-4" />
                Save Changes
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Email / SMTP Section ─── */
function EmailSection({ email_settings }: { email_settings?: EmailSettings }) {
  const defaults = email_settings ?? {
    mailer: 'smtp',
    host: '',
    port: '587',
    encryption: 'tls',
    username: '',
    password: '',
    from_address: '',
    from_name: '',
    is_configured: false,
  };
  const { data, setData, patch, processing, recentlySuccessful } = useForm({
    mailer: defaults.mailer,
    host: defaults.host,
    port: defaults.port,
    encryption: defaults.encryption,
    username: defaults.username,
    password: defaults.password,
    from_address: defaults.from_address,
    from_name: defaults.from_name,
  });

  const [testTo, setTestTo] = useState('');
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/email');
  };

  async function sendTest() {
    if (!testTo) return;
    setTesting(true);
    setTestResult(null);
    try {
      const csrf =
        (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '';
      const res = await fetch('/settings/email/test', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ to: testTo }),
        credentials: 'same-origin',
      });
      const json = await res.json();
      setTestResult(json);
    } catch {
      setTestResult({ ok: false, message: 'Network error — could not reach server.' });
    } finally {
      setTesting(false);
    }
  }

  const PRESETS: Record<string, { host: string; port: string; encryption: string }> = {
    gmail: { host: 'smtp.gmail.com', port: '587', encryption: 'tls' },
    outlook: { host: 'smtp.office365.com', port: '587', encryption: 'tls' },
    yahoo: { host: 'smtp.mail.yahoo.com', port: '587', encryption: 'tls' },
    mailpit: { host: 'mailpit', port: '1025', encryption: 'none' },
    sendgrid: { host: 'smtp.sendgrid.net', port: '587', encryption: 'tls' },
    mailgun: { host: 'smtp.mailgun.org', port: '587', encryption: 'tls' },
    zoho: { host: 'smtp.zoho.com', port: '587', encryption: 'tls' },
  };

  const isConfigured = !!(data.host && data.host.trim().length > 0);

  return (
    <div className="space-y-6 max-w-2xl">
      {/* Status banner — reactive to live form state */}
      <Card
        className={
          isConfigured
            ? 'border-success/20 bg-success/5 dark:bg-success/20'
            : 'border-warning/20 bg-warning/5 dark:bg-warning/20'
        }
      >
        <CardContent className="flex items-center gap-3 p-4">
          {isConfigured ? (
            <>
              <CheckCircle className="h-5 w-5 text-success shrink-0" />
              <div>
                <p className="text-sm font-medium text-success dark:text-success">
                  SMTP is configured
                </p>
                <p className="text-xs text-success dark:text-success/80">
                  Emails are being sent via {data.host}
                </p>
              </div>
            </>
          ) : (
            <>
              <XCircle className="h-5 w-5 text-warning shrink-0" />
              <div>
                <p className="text-sm font-medium text-warning dark:text-warning">
                  SMTP not configured
                </p>
                <p className="text-xs text-warning dark:text-warning/80">
                  Configure below to enable email notifications and password resets
                </p>
              </div>
            </>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Server className="h-5 w-5" />
            SMTP Configuration
          </CardTitle>
          <CardDescription>
            These settings apply to all system emails: notifications, password resets, approval
            alerts.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-5">
            {recentlySuccessful && <SuccessBanner message="Email settings saved successfully." />}

            {/* Quick presets */}
            <div className="space-y-1.5">
              <Label>Quick Setup Preset</Label>
              <div className="flex flex-wrap gap-2">
                {Object.entries(PRESETS).map(([key, preset]) => (
                  <button
                    key={key}
                    type="button"
                    onClick={() => {
                      setData((d) => ({
                        ...d,
                        host: preset.host,
                        port: preset.port,
                        encryption: preset.encryption,
                        mailer: key === 'mailpit' ? 'mailpit' : 'smtp',
                      }));
                    }}
                    className="rounded-full border px-3 py-1 text-xs font-medium hover:bg-primary hover:text-primary-foreground transition-colors capitalize"
                  >
                    {key}
                  </button>
                ))}
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label>Mail Driver</Label>
                <Select value={data.mailer} onValueChange={(v) => setData('mailer', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="smtp">SMTP (Live)</SelectItem>
                    <SelectItem value="mailpit">Mailpit (Local Test)</SelectItem>
                    <SelectItem value="log">Log (Debug Only)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label>Encryption</Label>
                <Select value={data.encryption} onValueChange={(v) => setData('encryption', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="tls">TLS (recommended)</SelectItem>
                    <SelectItem value="ssl">SSL</SelectItem>
                    <SelectItem value="none">None</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label>SMTP Host</Label>
                <Input
                  value={data.host}
                  onChange={(e) => setData('host', e.target.value)}
                  placeholder="smtp.gmail.com"
                />
              </div>
              <div className="space-y-1.5">
                <Label>Port</Label>
                <Input
                  value={data.port}
                  onChange={(e) => setData('port', e.target.value)}
                  placeholder="587"
                />
              </div>
              <div className="space-y-1.5">
                <Label>Username</Label>
                <Input
                  value={data.username}
                  onChange={(e) => setData('username', e.target.value)}
                  placeholder="you@gmail.com"
                  autoComplete="off"
                />
              </div>
              <div className="space-y-1.5">
                <Label>Password / App Password</Label>
                <Input
                  type="password"
                  value={data.password}
                  onChange={(e) => setData('password', e.target.value)}
                  placeholder="Leave blank to keep existing"
                  autoComplete="new-password"
                />
              </div>
              <div className="space-y-1.5">
                <Label>From Address</Label>
                <Input
                  type="email"
                  value={data.from_address}
                  onChange={(e) => setData('from_address', e.target.value)}
                  placeholder="noreply@yourcompany.com"
                />
              </div>
              <div className="space-y-1.5">
                <Label>From Name</Label>
                <Input
                  value={data.from_name}
                  onChange={(e) => setData('from_name', e.target.value)}
                  placeholder="WarehouseOps"
                />
              </div>
            </div>

            <div className="flex justify-end">
              <Button type="submit" disabled={processing}>
                <Save className="mr-1.5 h-4 w-4" />
                Save Email Settings
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {/* Test email */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <FlaskConical className="h-5 w-5" />
            Send Test Email
          </CardTitle>
          <CardDescription>
            Verify your SMTP configuration is working by sending a test email.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {testResult && (
            <div
              className={`flex items-center gap-2 rounded-md border px-4 py-3 text-sm ${testResult.ok ? 'border-success/20 bg-success/5 text-success' : 'border-destructive/20 bg-destructive/5 text-destructive'}`}
            >
              {testResult.ok ? (
                <CheckCircle className="h-4 w-4 shrink-0" />
              ) : (
                <XCircle className="h-4 w-4 shrink-0" />
              )}
              {testResult.message}
            </div>
          )}
          <div className="flex gap-3">
            <Input
              type="email"
              value={testTo}
              onChange={(e) => setTestTo(e.target.value)}
              placeholder="test@example.com"
              className="flex-1"
            />
            <Button variant="outline" disabled={testing || !testTo} onClick={sendTest}>
              {testing ? (
                <>
                  <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                  Sending…
                </>
              ) : (
                <>
                  <Mail className="mr-1.5 h-4 w-4" />
                  Send Test
                </>
              )}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Gmail help */}
      <Card className="border-dashed">
        <CardContent className="p-4 space-y-2">
          <p className="text-sm font-medium flex items-center gap-2">
            <Wifi className="h-4 w-4" />
            Gmail / Google Workspace Setup
          </p>
          <ol className="text-xs text-muted-foreground space-y-1 list-decimal list-inside">
            <li>
              Go to <strong>Google Account → Security → 2-Step Verification</strong> (must be
              enabled)
            </li>
            <li>
              Under 2-Step Verification, scroll down to <strong>App Passwords</strong>
            </li>
            <li>Create a new App Password — name it "WarehouseOps"</li>
            <li>Copy the 16-character password and paste it in the Password field above</li>
            <li>
              Use <strong>smtp.gmail.com</strong>, port <strong>587</strong>, encryption{' '}
              <strong>TLS</strong>
            </li>
          </ol>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Label Printer Section ─── */
function LabelPrinterSection({ printer_settings }: { printer_settings?: PrinterSettings }) {
  const defaults = printer_settings ?? {
    enabled: false,
    type: 'network',
    ip_address: '',
    port: '9100',
    dpi: '203',
    label_width_mm: '100',
    label_height_mm: '50',
    copies: '1',
    printer_name: '',
  };
  const { data, setData, patch, processing, recentlySuccessful } = useForm({
    enabled: defaults.enabled,
    type: defaults.type,
    ip_address: defaults.ip_address,
    port: defaults.port,
    dpi: defaults.dpi,
    label_width_mm: defaults.label_width_mm,
    label_height_mm: defaults.label_height_mm,
    copies: defaults.copies,
    printer_name: defaults.printer_name,
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/printer');
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Printer className="h-5 w-5" />
            Label Printer Configuration
          </CardTitle>
          <CardDescription>
            Configure your thermal label printer for printing barcode labels, stock labels, and
            shipping labels.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-5">
            {recentlySuccessful && <SuccessBanner message="Printer settings saved." />}

            <div className="flex items-center justify-between rounded-lg border p-4">
              <div>
                <p className="text-sm font-medium">Enable Label Printing</p>
                <p className="text-xs text-muted-foreground">
                  Show print buttons throughout the system
                </p>
              </div>
              <Switch checked={data.enabled} onCheckedChange={(v) => setData('enabled', v)} />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label>Connection Type</Label>
                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="network">Network (TCP/IP)</SelectItem>
                    <SelectItem value="usb">USB (Local)</SelectItem>
                    <SelectItem value="windows">Windows Printer Share</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label>DPI Resolution</Label>
                <Select value={data.dpi} onValueChange={(v) => setData('dpi', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="203">203 DPI (Standard)</SelectItem>
                    <SelectItem value="300">300 DPI (High Quality)</SelectItem>
                    <SelectItem value="600">600 DPI (Ultra High)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              {data.type === 'network' && (
                <>
                  <div className="space-y-1.5">
                    <Label>Printer IP Address</Label>
                    <Input
                      value={data.ip_address}
                      onChange={(e) => setData('ip_address', e.target.value)}
                      placeholder="192.168.1.100"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label>TCP Port</Label>
                    <Input
                      value={data.port}
                      onChange={(e) => setData('port', e.target.value)}
                      placeholder="9100"
                    />
                  </div>
                </>
              )}
              {data.type === 'windows' && (
                <div className="space-y-1.5 md:col-span-2">
                  <Label>Printer Share Name</Label>
                  <Input
                    value={data.printer_name}
                    onChange={(e) => setData('printer_name', e.target.value)}
                    placeholder="\\SERVER\ZEBRA-ZT230"
                  />
                </div>
              )}
              <div className="space-y-1.5">
                <Label>Label Width (mm)</Label>
                <Input
                  type="number"
                  value={data.label_width_mm}
                  onChange={(e) => setData('label_width_mm', e.target.value)}
                  placeholder="100"
                />
              </div>
              <div className="space-y-1.5">
                <Label>Label Height (mm)</Label>
                <Input
                  type="number"
                  value={data.label_height_mm}
                  onChange={(e) => setData('label_height_mm', e.target.value)}
                  placeholder="50"
                />
              </div>
              <div className="space-y-1.5">
                <Label>Default Copies</Label>
                <Select value={data.copies} onValueChange={(v) => setData('copies', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {[1, 2, 3, 4, 5].map((n) => (
                      <SelectItem key={n} value={String(n)}>
                        {n} cop{n === 1 ? 'y' : 'ies'}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="flex justify-end">
              <Button type="submit" disabled={processing}>
                <Save className="mr-1.5 h-4 w-4" />
                Save Printer Settings
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

/* ─── Scanner Section ─── */
function ScannerSection({ scanner_settings }: { scanner_settings?: ScannerSettings }) {
  const defaults = scanner_settings ?? {
    enabled: true,
    mode: 'validate',
    sound_enabled: true,
    auto_submit: true,
    beep_on_success: true,
    beep_on_error: true,
  };
  const { data, setData, patch, processing, recentlySuccessful } = useForm({ ...defaults });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch('/settings/scanner');
  };

  const toggles = [
    { key: 'sound_enabled', label: 'Enable Beep Sounds', desc: 'Play audio feedback on each scan' },
    {
      key: 'auto_submit',
      label: 'Auto-Submit on Scan',
      desc: 'Immediately process when scanner sends Enter',
    },
    { key: 'beep_on_success', label: 'Beep on Success', desc: 'Play success tone for valid scans' },
    {
      key: 'beep_on_error',
      label: 'Beep on Error',
      desc: 'Play error tone for unknown/failed scans',
    },
  ] as const;

  return (
    <div className="space-y-6 max-w-2xl">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ScanLine className="h-5 w-5" />
            Barcode Scanner Settings
          </CardTitle>
          <CardDescription>
            Configure default behaviour for barcode and QR code scanning across the system.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="space-y-5">
            {recentlySuccessful && <SuccessBanner message="Scanner settings saved." />}

            <div className="flex items-center justify-between rounded-lg border p-4">
              <div>
                <p className="text-sm font-medium">Enable Scanner</p>
                <p className="text-xs text-muted-foreground">
                  Show scanner input across waybill and inventory pages
                </p>
              </div>
              <Switch checked={data.enabled} onCheckedChange={(v) => setData('enabled', v)} />
            </div>

            <div className="space-y-1.5">
              <Label>Default Scan Mode</Label>
              <Select value={data.mode} onValueChange={(v) => setData('mode', v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="validate">Validate (read-only lookup)</SelectItem>
                  <SelectItem value="dispatch">Dispatch (mark as dispatched)</SelectItem>
                  <SelectItem value="receive_return">Receive Return</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              {toggles.map((t) => (
                <div
                  key={t.key}
                  className="flex items-center justify-between py-2 border-b last:border-0"
                >
                  <div>
                    <p className="text-sm font-medium">{t.label}</p>
                    <p className="text-xs text-muted-foreground">{t.desc}</p>
                  </div>
                  <Switch checked={!!data[t.key]} onCheckedChange={(v) => setData(t.key, v)} />
                </div>
              ))}
            </div>

            <div className="flex justify-end">
              <Button type="submit" disabled={processing}>
                <Save className="mr-1.5 h-4 w-4" />
                Save Scanner Settings
              </Button>
            </div>
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
        {integrations.map((intg) => (
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
                <Badge
                  variant={intg.status === 'connected' ? 'default' : 'outline'}
                  className={intg.status === 'connected' ? 'bg-success/10 text-success' : ''}
                >
                  {intg.status === 'connected' ? 'Connected' : 'Not Connected'}
                </Badge>
              </div>
              <div className="mt-4 flex justify-end">
                <Button variant="outline" size="sm">
                  {intg.status === 'connected' ? 'Manage' : 'Connect'}
                </Button>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

/* ─── Main Settings Page ─── */
export default function SettingsPage({
  user,
  system_settings = {},
  integrations = [],
  email_settings,
  printer_settings,
  scanner_settings,
}: Props) {
  const [activeTab, setActiveTab] = useState('profile');

  const navItems = [
    { id: 'profile', label: 'Profile', icon: User, desc: 'Personal information', group: null },
    { id: 'security', label: 'Security', icon: Shield, desc: 'Password & policy', group: null },
    {
      id: 'notifications',
      label: 'Notifications',
      icon: Bell,
      desc: 'Email & SMS alerts',
      group: null,
    },
    { id: 'system', label: 'System', icon: Sliders, desc: 'General settings', group: 'System' },
    {
      id: 'email',
      label: 'Email (SMTP)',
      icon: Mail,
      desc: 'Centralized mail server',
      group: 'System',
    },
    {
      id: 'printer',
      label: 'Label Printer',
      icon: Printer,
      desc: 'Thermal printer config',
      group: 'System',
    },
    {
      id: 'scanner',
      label: 'Barcode Scanner',
      icon: ScanLine,
      desc: 'Scanner behaviour',
      group: 'System',
    },
    {
      id: 'integrations',
      label: 'Integrations',
      icon: Plug,
      desc: 'Connected apps',
      group: 'System',
    },
  ];

  return (
    <AppLayout>
      <Head title="Settings" />
      <div className="flex h-[calc(100vh-4rem)] overflow-hidden">
        {/* Sidebar */}
        <aside className="w-64 shrink-0 border-r bg-sidebar hidden md:block">
          <div className="p-4">
            <h2 className="text-lg font-semibold tracking-tight">Settings</h2>
            <p className="text-xs text-muted-foreground">
              Manage your account and application preferences
            </p>
          </div>
          <ScrollArea className="h-[calc(100%-5rem)]">
            <nav className="p-2 space-y-0.5">
              {navItems.map((item, idx) => {
                const Icon = item.icon;
                const active = activeTab === item.id;
                const prevItem = navItems[idx - 1];
                const showGroupHeader = item.group && (!prevItem || prevItem.group !== item.group);
                return (
                  <div key={item.id}>
                    {showGroupHeader && (
                      <div className="px-3 pt-3 pb-1">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {item.group}
                        </p>
                      </div>
                    )}
                    <button
                      onClick={() => setActiveTab(item.id)}
                      className={cn(
                        'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition-colors',
                        active
                          ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium'
                          : 'text-sidebar-foreground hover:bg-sidebar-accent/50'
                      )}
                    >
                      <Icon className="h-4 w-4 shrink-0" />
                      <div className="min-w-0">
                        <div className="truncate">{item.label}</div>
                        <div className="text-[10px] text-muted-foreground truncate">
                          {item.desc}
                        </div>
                      </div>
                      {active && <ChevronRight className="ml-auto h-4 w-4 shrink-0 opacity-50" />}
                    </button>
                  </div>
                );
              })}
            </nav>
          </ScrollArea>
        </aside>

        {/* Mobile dropdown */}
        <div className="md:hidden p-4 border-b w-full">
          <Select value={activeTab} onValueChange={setActiveTab}>
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {navItems.map((item) => (
                <SelectItem key={item.id} value={item.id}>
                  {item.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Main content */}
        <main className="flex-1 overflow-auto p-6">
          <div className="max-w-5xl mx-auto">
            <div className="mb-6">
              <h1 className="text-xl font-bold font-display tracking-tight">
                {navItems.find((n) => n.id === activeTab)?.label ?? 'Settings'}
              </h1>
              <p className="text-sm text-muted-foreground">
                {navItems.find((n) => n.id === activeTab)?.desc ?? ''}
              </p>
            </div>

            {activeTab === 'profile' && <ProfileSection user={user} />}
            {activeTab === 'security' && <SecuritySection />}
            {activeTab === 'notifications' && <NotificationsSection />}
            {activeTab === 'system' && <SystemSection system_settings={system_settings} />}
            {activeTab === 'email' && <EmailSection email_settings={email_settings} />}
            {activeTab === 'printer' && <LabelPrinterSection printer_settings={printer_settings} />}
            {activeTab === 'scanner' && <ScannerSection scanner_settings={scanner_settings} />}
            {activeTab === 'integrations' && <IntegrationsSection integrations={integrations} />}
          </div>
        </main>
      </div>
    </AppLayout>
  );
}
