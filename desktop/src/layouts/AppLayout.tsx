import { useState, useEffect } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import {
  LayoutDashboard,
  QrCode,
  Upload,
  Activity,
  MessageSquare,
  Users,
  Settings,
  LogOut,
  ChevronLeft,
  ChevronRight,
  RefreshCw,
  Download,
} from 'lucide-react';
import { api } from '@/lib/api';
import { Button } from '@/components/ui/button';
import teccBanner from '@/assets/tecc-banner.png';
import type { User } from '@/types';

const navItems = [
  { path: '/', label: 'Dashboard', icon: LayoutDashboard },
  { path: '/scanner', label: 'Scanner', icon: QrCode },
  { path: '/import', label: 'Import', icon: Upload },
  { path: '/sms', label: 'SMS', icon: MessageSquare },
  { path: '/monitoring', label: 'Monitoring', icon: Activity },
  { path: '/users', label: 'Users', icon: Users },
];

const bottomNavItems = [
  { path: '/settings', label: 'Settings', icon: Settings },
];

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const [collapsed, setCollapsed] = useState(false);
  const [user, setUser] = useState<User | null>(null);
  const [updateAvailable, setUpdateAvailable] = useState(false);
  const [updateDownloaded, setUpdateDownloaded] = useState(false);
  const [appVersion, setAppVersion] = useState('');
  const navigate = useNavigate();

  useEffect(() => {
    api.getUser().then(setUser).catch(() => navigate('/login'));
    window.electronAPI?.getVersion().then(setAppVersion);

    window.electronAPI?.onUpdaterAvailable(() => setUpdateAvailable(true));
    window.electronAPI?.onUpdaterDownloaded(() => {
      setUpdateAvailable(false);
      setUpdateDownloaded(true);
    });
  }, [navigate]);

  const handleLogout = async () => {
    await api.logout();
    navigate('/login');
  };

  const handleInstallUpdate = () => {
    window.electronAPI?.installUpdate();
  };

  return (
    <div className="flex h-screen bg-background">
      {/* Sidebar */}
      <aside
        className={`flex flex-col border-r bg-card transition-all duration-200 ${
          collapsed ? 'w-16' : 'w-64'
        }`}
      >
        {/* Logo */}
        <div className="flex h-14 items-center border-b px-3">
          {!collapsed && (
            <img src={teccBanner} alt="TECC" className="h-8 object-contain" />
          )}
          <button
            onClick={() => setCollapsed(!collapsed)}
            className={`ml-auto rounded-md p-1.5 hover:bg-accent ${collapsed ? 'mx-auto' : ''}`}
          >
            {collapsed ? <ChevronRight className="h-4 w-4" /> : <ChevronLeft className="h-4 w-4" />}
          </button>
        </div>

        {/* Navigation */}
        <nav className="flex-1 space-y-1 p-2">
          {navItems.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors ${
                  isActive
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                } ${collapsed ? 'justify-center' : ''}`
              }
              end={item.path === '/'}
            >
              <item.icon className="h-5 w-5 shrink-0" />
              {!collapsed && <span>{item.label}</span>}
            </NavLink>
          ))}
        </nav>

        {/* Update notification */}
        {updateDownloaded && (
          <div className="mx-2 mb-2">
            <button
              onClick={handleInstallUpdate}
              className="flex w-full items-center gap-2 rounded-lg bg-green-500/10 px-3 py-2 text-sm text-green-600 hover:bg-green-500/20 transition-colors"
            >
              <Download className="h-4 w-4" />
              {!collapsed && <span>Install Update</span>}
            </button>
          </div>
        )}
        {updateAvailable && !updateDownloaded && (
          <div className="mx-2 mb-2">
            <div className="flex items-center gap-2 rounded-lg bg-blue-500/10 px-3 py-2 text-sm text-blue-600">
              <RefreshCw className="h-4 w-4 animate-spin" />
              {!collapsed && <span>Downloading...</span>}
            </div>
          </div>
        )}

        {/* Bottom nav (Settings) */}
        <div className="border-t p-2 space-y-1">
          {bottomNavItems.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors ${
                  isActive
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                } ${collapsed ? 'justify-center' : ''}`
              }
            >
              <item.icon className="h-4 w-4 shrink-0" />
              {!collapsed && <span>{item.label}</span>}
            </NavLink>
          ))}

          {/* User info */}
          {user && (
            <div className={`flex items-center gap-3 rounded-lg px-3 py-2 ${collapsed ? 'justify-center' : ''}`}>
              <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-sm font-semibold">
                {user.name.charAt(0).toUpperCase()}
              </div>
              {!collapsed && (
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate">{user.name}</p>
                  <p className="text-xs text-muted-foreground truncate">{user.role}</p>
                </div>
              )}
            </div>
          )}
          <Button
            variant="ghost"
            size="sm"
            onClick={handleLogout}
            className={`w-full ${collapsed ? 'justify-center px-0' : 'justify-start'}`}
          >
            <LogOut className="h-4 w-4" />
            {!collapsed && <span className="ml-2">Logout</span>}
          </Button>
          {!collapsed && appVersion && (
            <p className="text-center text-xs text-muted-foreground">v{appVersion}</p>
          )}
        </div>
      </aside>

      {/* Main Content */}
      <main className="flex-1 overflow-auto">
        <div className="p-6">{children}</div>
      </main>
    </div>
  );
}
