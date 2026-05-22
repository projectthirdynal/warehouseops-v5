import { useState, useEffect } from 'react';
import {
  User,
  Palette,
  Download,
  RefreshCw,
  CheckCircle,
  Loader2,
  Server,
  Shield,
  Info,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';

type Tab = 'profile' | 'appearance' | 'updates' | 'about';

export default function SettingsPage() {
  const [activeTab, setActiveTab] = useState<Tab>('profile');

  // Profile
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('');
  const [profileLoading, setProfileLoading] = useState(true);
  const [profileSaving, setProfileSaving] = useState(false);
  const [profileMsg, setProfileMsg] = useState('');

  // Password
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordSaving, setPasswordSaving] = useState(false);
  const [passwordMsg, setPasswordMsg] = useState('');

  // Appearance
  const [theme, setTheme] = useState<'light' | 'dark'>('light');

  // Updates
  const [appVersion, setAppVersion] = useState('');
  const [updateStatus, setUpdateStatus] = useState<'idle' | 'checking' | 'available' | 'downloading' | 'downloaded' | 'error' | 'up-to-date'>('idle');
  const [updateInfo, setUpdateInfo] = useState<string>('');
  const [downloadProgress, setDownloadProgress] = useState(0);

  useEffect(() => {
    // Load profile
    api.getUser().then(user => {
      setName(user.name);
      setEmail(user.email);
      setRole(user.role);
      setTheme(user.theme === 'dark' ? 'dark' : 'light');
      setProfileLoading(false);
    }).catch(() => setProfileLoading(false));

    // Load version
    window.electronAPI?.getVersion().then(v => setAppVersion(v || '1.0.0'));

    // Update listeners
    window.electronAPI?.onUpdaterChecking(() => setUpdateStatus('checking'));
    window.electronAPI?.onUpdaterAvailable((_e, info: unknown) => {
      setUpdateStatus('available');
      const i = info as { version?: string };
      setUpdateInfo(i?.version || 'New version');
    });
    window.electronAPI?.onUpdaterNotAvailable(() => setUpdateStatus('up-to-date'));
    window.electronAPI?.onUpdaterProgress((_e, progress: unknown) => {
      setUpdateStatus('downloading');
      const p = progress as { percent?: number };
      setDownloadProgress(p?.percent || 0);
    });
    window.electronAPI?.onUpdaterDownloaded((_e, info: unknown) => {
      setUpdateStatus('downloaded');
      const i = info as { version?: string };
      setUpdateInfo(i?.version || '');
    });
    window.electronAPI?.onUpdaterError((_e, msg: string) => {
      setUpdateStatus('error');
      setUpdateInfo(msg || 'Update check failed');
    });
  }, []);

  const handleSaveProfile = async () => {
    setProfileSaving(true);
    setProfileMsg('');
    try {
      await api.updateProfile({ name });
      setProfileMsg('Profile updated');
    } catch {
      setProfileMsg('Failed to update profile');
    } finally {
      setProfileSaving(false);
    }
  };

  const handleChangePassword = async () => {
    if (newPassword !== confirmPassword) {
      setPasswordMsg('Passwords do not match');
      return;
    }
    if (newPassword.length < 8) {
      setPasswordMsg('Password must be at least 8 characters');
      return;
    }
    setPasswordSaving(true);
    setPasswordMsg('');
    try {
      await api.updatePassword({ current_password: currentPassword, password: newPassword, password_confirmation: confirmPassword });
      setPasswordMsg('Password changed');
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { message?: string } } };
      setPasswordMsg(axiosErr?.response?.data?.message || 'Failed to change password');
    } finally {
      setPasswordSaving(false);
    }
  };

  const handleThemeChange = (newTheme: 'light' | 'dark') => {
    setTheme(newTheme);
    document.documentElement.classList.toggle('dark', newTheme === 'dark');
    localStorage.setItem('theme', newTheme);
    api.updateAppearance({ theme: newTheme }).catch(() => {});
  };

  const handleCheckUpdate = () => {
    setUpdateStatus('checking');
    window.electronAPI?.checkUpdate();
  };

  const handleInstallUpdate = () => {
    window.electronAPI?.installUpdate();
  };

  const tabs: { key: Tab; label: string; icon: React.ComponentType<{ className?: string }> }[] = [
    { key: 'profile', label: 'Profile', icon: User },
    { key: 'appearance', label: 'Appearance', icon: Palette },
    { key: 'updates', label: 'Updates', icon: Download },
    { key: 'about', label: 'About', icon: Info },
  ];

  if (profileLoading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Settings</h1>
        <p className="text-muted-foreground">Manage your profile, appearance, and application updates</p>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 border-b">
        {tabs.map(tab => (
          <button
            key={tab.key}
            onClick={() => setActiveTab(tab.key)}
            className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors ${
              activeTab === tab.key
                ? 'border-primary text-primary'
                : 'border-transparent text-muted-foreground hover:text-foreground'
            }`}
          >
            <tab.icon className="h-4 w-4" />
            {tab.label}
          </button>
        ))}
      </div>

      {/* === PROFILE === */}
      {activeTab === 'profile' && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>Profile Information</CardTitle>
              <CardDescription>Update your name and view account details</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Name</label>
                <input
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Email</label>
                <input type="email" value={email} disabled className="flex h-10 w-full rounded-md border border-input bg-muted px-3 py-2 text-sm text-muted-foreground" />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Role</label>
                <div className="flex items-center gap-2">
                  <Badge>{role}</Badge>
                </div>
              </div>
              {profileMsg && <p className={`text-sm ${profileMsg.includes('Failed') ? 'text-red-600' : 'text-green-600'}`}>{profileMsg}</p>}
              <Button onClick={handleSaveProfile} disabled={profileSaving}>
                {profileSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                Save Profile
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2"><Shield className="h-5 w-5" /> Change Password</CardTitle>
              <CardDescription>Update your account password</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <label className="text-sm font-medium">Current Password</label>
                <input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">New Password</label>
                <input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
              </div>
              <div className="space-y-2">
                <label className="text-sm font-medium">Confirm Password</label>
                <input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />
              </div>
              {passwordMsg && <p className={`text-sm ${passwordMsg.includes('Failed') || passwordMsg.includes('match') || passwordMsg.includes('must') ? 'text-red-600' : 'text-green-600'}`}>{passwordMsg}</p>}
              <Button onClick={handleChangePassword} disabled={passwordSaving || !currentPassword || !newPassword}>
                {passwordSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
                Change Password
              </Button>
            </CardContent>
          </Card>
        </div>
      )}

      {/* === APPEARANCE === */}
      {activeTab === 'appearance' && (
        <Card>
          <CardHeader>
            <CardTitle>Theme</CardTitle>
            <CardDescription>Choose your preferred color scheme</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 md:grid-cols-2 max-w-md">
              <button
                onClick={() => handleThemeChange('light')}
                className={`flex flex-col items-center gap-3 rounded-lg border-2 p-6 transition-colors ${
                  theme === 'light' ? 'border-primary bg-primary/5' : 'border-muted hover:border-primary/50'
                }`}
              >
                <div className="w-full h-20 rounded-md bg-white border shadow-sm flex items-center justify-center">
                  <div className="w-8 h-2 rounded bg-gray-300" />
                </div>
                <span className="text-sm font-medium">Light</span>
                {theme === 'light' && <CheckCircle className="h-4 w-4 text-primary" />}
              </button>
              <button
                onClick={() => handleThemeChange('dark')}
                className={`flex flex-col items-center gap-3 rounded-lg border-2 p-6 transition-colors ${
                  theme === 'dark' ? 'border-primary bg-primary/5' : 'border-muted hover:border-primary/50'
                }`}
              >
                <div className="w-full h-20 rounded-md bg-gray-900 border border-gray-700 flex items-center justify-center">
                  <div className="w-8 h-2 rounded bg-gray-600" />
                </div>
                <span className="text-sm font-medium">Dark</span>
                {theme === 'dark' && <CheckCircle className="h-4 w-4 text-primary" />}
              </button>
            </div>
          </CardContent>
        </Card>
      )}

      {/* === UPDATES === */}
      {activeTab === 'updates' && (
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Application Updates</CardTitle>
              <CardDescription>Check for and install new versions of TECC Desktop</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between p-4 rounded-lg border">
                <div>
                  <p className="font-medium">Current Version</p>
                  <p className="text-2xl font-bold text-primary">v{appVersion}</p>
                </div>
                <Button onClick={handleCheckUpdate} disabled={updateStatus === 'checking' || updateStatus === 'downloading'}>
                  {updateStatus === 'checking' ? (
                    <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Checking...</>
                  ) : (
                    <><RefreshCw className="mr-2 h-4 w-4" /> Check for Updates</>
                  )}
                </Button>
              </div>

              {/* Status display */}
              {updateStatus === 'up-to-date' && (
                <div className="flex items-center gap-3 p-4 rounded-lg bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-300">
                  <CheckCircle className="h-5 w-5" />
                  <div>
                    <p className="font-medium">You're up to date!</p>
                    <p className="text-sm">TECC Desktop v{appVersion} is the latest version.</p>
                  </div>
                </div>
              )}

              {updateStatus === 'available' && (
                <div className="flex items-center gap-3 p-4 rounded-lg bg-blue-50 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                  <Download className="h-5 w-5" />
                  <div className="flex-1">
                    <p className="font-medium">Update available: v{updateInfo}</p>
                    <p className="text-sm">Downloading automatically...</p>
                  </div>
                </div>
              )}

              {updateStatus === 'downloading' && (
                <div className="p-4 rounded-lg bg-blue-50 dark:bg-blue-950 text-blue-700 dark:text-blue-300 space-y-2">
                  <div className="flex items-center gap-3">
                    <Loader2 className="h-5 w-5 animate-spin" />
                    <p className="font-medium">Downloading update...</p>
                  </div>
                  <div className="w-full h-2 rounded-full bg-blue-200 dark:bg-blue-800">
                    <div className="h-full rounded-full bg-blue-600 transition-all" style={{ width: `${downloadProgress}%` }} />
                  </div>
                  <p className="text-sm">{Math.round(downloadProgress)}% complete</p>
                </div>
              )}

              {updateStatus === 'downloaded' && (
                <div className="flex items-center justify-between p-4 rounded-lg bg-green-50 dark:bg-green-950 text-green-700 dark:text-green-300">
                  <div className="flex items-center gap-3">
                    <CheckCircle className="h-5 w-5" />
                    <div>
                      <p className="font-medium">Update ready to install</p>
                      <p className="text-sm">v{updateInfo} has been downloaded. Restart to apply.</p>
                    </div>
                  </div>
                  <Button onClick={handleInstallUpdate} className="bg-green-600 hover:bg-green-700 text-white">
                    Restart & Update
                  </Button>
                </div>
              )}

              {updateStatus === 'error' && (
                <div className="flex items-center gap-3 p-4 rounded-lg bg-red-50 dark:bg-red-950 text-red-700 dark:text-red-300">
                  <Info className="h-5 w-5" />
                  <div>
                    <p className="font-medium">Update check failed</p>
                    <p className="text-sm">{updateInfo}</p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Connection</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground flex items-center gap-2"><Server className="h-4 w-4" /> Server URL</span>
                <span className="text-sm font-mono">{api.getServerUrl()}</span>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* === ABOUT === */}
      {activeTab === 'about' && (
        <Card>
          <CardHeader>
            <CardTitle>About TECC Desktop</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="space-y-2">
              <div className="flex justify-between"><span className="text-muted-foreground">Application</span><span className="font-medium">TECC Desktop</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Version</span><span className="font-medium">v{appVersion}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Platform</span><span className="font-medium">Electron + React</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Server</span><span className="font-mono text-sm">{api.getServerUrl()}</span></div>
            </div>
            <p className="text-sm text-muted-foreground pt-4 border-t">
              Thirdynal E-Commerce System (TECC) Desktop provides admin access to dashboard, scanner, waybill import, SMS messaging, and real-time monitoring.
            </p>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
