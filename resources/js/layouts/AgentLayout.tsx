import { PropsWithChildren, useState, useEffect, useCallback, useRef } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
  Phone,
  Headphones,
  Search,
  Settings,
  LogOut,
  Menu,
  X,
  ChevronRight,
  Bell,
  User,
  LayoutDashboard,
  CircleDot,
  CircleSlash,
  Trophy,
  Smartphone,
} from 'lucide-react';
import axios from 'axios';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { usePolling } from '@/hooks/use-polling';
import type { PageProps } from '@/types';

interface NavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  badge?: number;
}

const agentNav: NavItem[] = [
  { name: 'Dashboard', href: '/agent/dashboard', icon: LayoutDashboard },
  { name: 'My Leads', href: '/agent/leads', icon: Phone },
  { name: 'Track Waybill', href: '/agent/tracking', icon: Search },
  { name: 'Achievements', href: '/agent/gamification', icon: Trophy },
  { name: 'Tickets', href: '/tickets', icon: Headphones },
];

const agentBottomNav: NavItem[] = [
  { name: 'App Settings', href: '/agent/pwa-settings', icon: Smartphone },
  { name: 'Settings', href: '/settings', icon: Settings },
];

interface AvailabilityState {
  is_available: boolean;
  last_seen_at: string | null;
  idle_threshold_minutes: number;
  idle_minutes: number | null;
  remaining_minutes: number | null;
  shift_start: string | null;
  shift_end: string | null;
  in_shift: boolean;
}

export default function AgentLayout({ children }: PropsWithChildren) {
  const { auth } = usePage<PageProps>().props;
  const [mobileOpen, setMobileOpen] = useState(false);
  const [availability, setAvailability] = useState<AvailabilityState | null>(null);
  const [toggling, setToggling] = useState(false);
  const heartbeatTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/';

  const isActive = (href: string) => currentPath.startsWith(href);

  const getInitials = (name: string) =>
    name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);

  const fetchAvailability = useCallback(async () => {
    try {
      const { data } = await axios.get<AvailabilityState>('/api/agent/availability');
      setAvailability(data);
    } catch {
      // silently fail — non-critical
    }
  }, []);

  const sendHeartbeat = useCallback(async () => {
    try {
      const { data } = await axios.post<AvailabilityState & { restored: boolean }>(
        '/api/agent/heartbeat'
      );
      if (data.restored) {
        setAvailability({
          is_available: data.is_available,
          last_seen_at: data.last_seen_at,
          idle_threshold_minutes: data.idle_threshold_minutes,
          idle_minutes: 0,
          remaining_minutes: data.idle_threshold_minutes,
          shift_start: availability?.shift_start ?? null,
          shift_end: availability?.shift_end ?? null,
          in_shift: availability?.in_shift ?? true,
        });
      }
    } catch {
      // silently fail
    }
  }, [availability]);

  const handleToggle = useCallback(async () => {
    if (!availability) return;
    setToggling(true);
    try {
      const { data } = await axios.post<AvailabilityState>('/api/agent/availability', {
        is_available: !availability.is_available,
      });
      setAvailability(data);
    } catch {
      // silently fail
    } finally {
      setToggling(false);
    }
  }, [availability]);

  // Fetch initial availability on mount
  useEffect(() => {
    fetchAvailability();
  }, [fetchAvailability]);

  // Send heartbeat every 2 minutes when available
  useEffect(() => {
    if (availability?.is_available) {
      heartbeatTimerRef.current = setInterval(sendHeartbeat, 120000);
      return () => {
        if (heartbeatTimerRef.current) clearInterval(heartbeatTimerRef.current);
      };
    }
  }, [availability?.is_available, sendHeartbeat]);

  // Poll availability status every 30s to detect auto-unavailable from backend
  usePolling(fetchAvailability, [], { interval: 30000, enabled: true });

  const NavLinks = ({ onClick }: { onClick?: () => void }) => (
    <>
      {agentNav.map((item) => {
        const Icon = item.icon;
        const active = isActive(item.href);
        return (
          <Link
            key={item.name}
            href={item.href}
            onClick={onClick}
            className={cn(
              'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition-colors',
              active
                ? 'bg-primary text-primary-foreground shadow-sm'
                : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
            )}
          >
            <Icon className="h-5 w-5 shrink-0" />
            <span className="flex-1">{item.name}</span>
            {item.badge && item.badge > 0 && (
              <Badge variant="destructive" className="h-5 px-1.5 text-[10px]">
                {item.badge}
              </Badge>
            )}
            {active && <ChevronRight className="h-4 w-4 opacity-60" />}
          </Link>
        );
      })}
    </>
  );

  return (
    <div className="flex h-screen bg-background">
      {/* Mobile overlay */}
      {mobileOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/50 backdrop-blur-sm lg:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      {/* Sidebar — desktop always visible, mobile slide-in */}
      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r bg-card shadow-xl transition-transform duration-300 lg:relative lg:translate-x-0',
          mobileOpen ? 'translate-x-0' : '-translate-x-full'
        )}
      >
        {/* Brand header */}
        <div className="flex h-16 items-center justify-between border-b px-5">
          <div className="flex items-center gap-3">
            <img src="/images/tecc-banner.png" alt="TECS" className="h-8 object-contain" />
            <div>
              <p className="text-sm font-bold leading-none">Agent Portal</p>
            </div>
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setMobileOpen(false)}
          >
            <X className="h-5 w-5" />
          </Button>
        </div>

        {/* User card */}
        <div className="border-b px-4 py-4">
          <div className="flex items-center gap-3 rounded-xl bg-accent/50 p-3">
            <Avatar className="h-10 w-10 ring-2 ring-primary/20">
              <AvatarImage src={auth?.user?.avatar_url} />
              <AvatarFallback className="bg-primary text-primary-foreground text-sm font-semibold">
                {auth?.user?.name ? getInitials(auth.user.name) : 'A'}
              </AvatarFallback>
            </Avatar>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-semibold truncate">{auth?.user?.name || 'Agent'}</p>
              <button
                onClick={handleToggle}
                disabled={toggling || !availability}
                className="flex items-center gap-1.5 mt-0.5 group cursor-pointer disabled:opacity-50"
              >
                {availability?.is_available ? (
                  <>
                    <CircleDot className="h-3.5 w-3.5 text-success group-hover:text-warning transition-colors" />
                    <span className="text-xs text-success group-hover:text-warning transition-colors">
                      {availability.remaining_minutes !== null &&
                      availability.remaining_minutes <= 5
                        ? `Auto-away in ${availability.remaining_minutes}m`
                        : 'Available'}
                    </span>
                  </>
                ) : (
                  <>
                    <CircleSlash className="h-3.5 w-3.5 text-muted-foreground group-hover:text-success transition-colors" />
                    <span className="text-xs text-muted-foreground group-hover:text-success transition-colors">
                      {availability?.shift_start && availability?.in_shift === false
                        ? 'Off Shift'
                        : 'Away'}
                    </span>
                  </>
                )}
              </button>
              {availability?.shift_start && availability?.shift_end && (
                <p className="text-[10px] text-muted-foreground/70 mt-0.5">
                  Shift: {availability.shift_start}–{availability.shift_end}
                </p>
              )}
            </div>
          </div>
        </div>

        {/* Navigation */}
        <nav className="flex-1 space-y-1 overflow-y-auto p-3">
          <p className="px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            Menu
          </p>
          <NavLinks onClick={() => setMobileOpen(false)} />
        </nav>

        {/* Bottom section */}
        <div className="border-t p-3 space-y-1">
          {agentBottomNav.map((item) => {
            const Icon = item.icon;
            const active = isActive(item.href);
            return (
              <Link
                key={item.name}
                href={item.href}
                className={cn(
                  'flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition-colors',
                  active
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                )}
              >
                <Icon className="h-4 w-4" />
                {item.name}
              </Link>
            );
          })}
          <Link href="/logout" method="post" as="button" className="w-full">
            <span className="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors w-full">
              <LogOut className="h-4 w-4" />
              Sign Out
            </span>
          </Link>
        </div>
      </aside>

      {/* Main content */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {/* Top bar */}
        <header className="flex h-16 items-center gap-4 border-b bg-card px-4 lg:px-6 shadow-sm">
          {/* Mobile menu button */}
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setMobileOpen(true)}
          >
            <Menu className="h-5 w-5" />
          </Button>

          {/* Page title area — left */}
          <div className="flex-1" />

          {/* Right actions */}
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="icon" className="relative">
              <Bell className="h-5 w-5" />
            </Button>
            <Link href="/settings">
              <Button variant="ghost" size="icon">
                <User className="h-5 w-5" />
              </Button>
            </Link>
          </div>
        </header>

        {/* Page content */}
        <main className="flex-1 overflow-y-auto p-4 lg:p-6">{children}</main>
      </div>
    </div>
  );
}
