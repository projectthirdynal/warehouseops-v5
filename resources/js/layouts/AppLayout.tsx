import { PropsWithChildren, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
  LayoutDashboard,
  Package,
  Users,
  Truck,
  ClipboardCheck,
  BarChart3,
  Settings,
  Bell,
  ChevronLeft,
  ChevronDown,
  LogOut,
  Menu,
  Phone,
  Recycle,
  UserCog,
  Headphones,
  Upload,
  MessageSquare,
  ShieldAlert,
  AlertOctagon,
  ScanLine,
  HelpCircle,
  Warehouse as WarehouseIcon,
  ShoppingCart,
  FileText,
  PackageCheck,
  Building2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Separator } from '@/components/ui/separator';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { Badge } from '@/components/ui/badge';
import type { PageProps } from '@/types';

interface NavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  badge?: number;
  permission?: string;
}

interface NavGroup {
  name: string;
  icon: React.ComponentType<{ className?: string }>;
  permission?: string;
  children: NavItem[];
}

type NavEntry = NavItem | NavGroup;

function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'children' in entry;
}

const navigation: NavEntry[] = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard },
  {
    name: 'Waybills',
    icon: Truck,
    permission: 'accounts',
    children: [
      { name: 'All Waybills', href: '/waybills', icon: Truck },
      { name: 'Scanner', href: '/waybills/scanner', icon: ScanLine },
      { name: 'Import', href: '/waybills/import', icon: Upload },
      { name: 'Claims', href: '/waybills/claims', icon: ShieldAlert },
      { name: 'Beyond SLA', href: '/waybills/claims/beyond-sla', icon: AlertOctagon },
      { name: 'Unknown', href: '/waybills/unknown', icon: HelpCircle },
    ],
  },
  { name: 'My Leads', href: '/agent/leads', icon: Phone },
  { name: 'Leads', href: '/leads', icon: Users, permission: 'leads_view' },
  { name: 'QC Review', href: '/qc', icon: ClipboardCheck, permission: 'qc' },
  { name: 'Recycling', href: '/recycling/pool', icon: Recycle, permission: 'leads_manage' },
  { name: 'Monitoring', href: '/monitoring/dashboard', icon: BarChart3, permission: 'leads_manage' },
  { name: 'Agents', href: '/agents/governance', icon: UserCog, permission: 'leads_manage' },
  { name: 'SMS', href: '/sms', icon: MessageSquare, permission: 'accounts' },
  { name: 'Orders', href: '/orders', icon: ClipboardCheck, permission: 'accounts' },
  { name: 'Finance', href: '/finance', icon: BarChart3, permission: 'accounts' },
  { name: 'Reports', href: '/reports', icon: ClipboardCheck, permission: 'accounts' },
  {
    name: 'Inventory',
    icon: WarehouseIcon,
    permission: 'accounts',
    children: [
      { name: 'Dashboard',  href: '/inventory',           icon: BarChart3 },
      { name: 'Movements',  href: '/inventory/movements', icon: Recycle },
      { name: 'Products',   href: '/products',            icon: Package },
      { name: 'Warehouses', href: '/warehouses',          icon: Building2 },
    ],
  },
  {
    name: 'Procurement',
    icon: ShoppingCart,
    permission: 'accounts',
    children: [
      { name: 'Suppliers',         href: '/procurement/suppliers', icon: Building2 },
      { name: 'Purchase Requests', href: '/procurement/requests',  icon: FileText },
      { name: 'Purchase Orders',   href: '/procurement/orders',    icon: ShoppingCart },
      { name: 'Receiving (GRN)',   href: '/procurement/receiving', icon: PackageCheck },
    ],
  },
  { name: 'Couriers', href: '/couriers', icon: Truck, permission: 'accounts' },
  { name: 'Tickets', href: '/tickets', icon: Headphones },
];

const bottomNav: NavItem[] = [
  { name: 'Settings', href: '/settings', icon: Settings, permission: 'settings' },
];

export default function AppLayout({ children }: PropsWithChildren) {
  const { auth } = usePage<PageProps>().props;
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/';

  // Auto-expand groups that contain the active path
  useEffect(() => {
    const autoExpand: Record<string, boolean> = {};
    navigation.forEach((entry) => {
      if (isNavGroup(entry)) {
        const hasActive = entry.children.some((child) => currentPath.startsWith(child.href));
        if (hasActive) autoExpand[entry.name] = true;
      }
    });
    setOpenGroups((prev) => ({ ...prev, ...autoExpand }));
  }, [currentPath]);

  useEffect(() => {
    const theme = auth?.user?.theme ?? 'light';
    const html = document.documentElement;
    if (theme === 'dark') {
      html.classList.add('dark');
    } else if (theme === 'system') {
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      html.classList.toggle('dark', prefersDark);
    } else {
      html.classList.remove('dark');
    }
  }, [auth?.user?.theme]);

  const isAgent = auth?.user?.role === 'agent';

  const isActive = (href: string) => {
    if (href === '/') return currentPath === '/';
    if (href === '/waybills') return currentPath === '/waybills';
    return currentPath.startsWith(href);
  };

  const isGroupActive = (group: NavGroup) =>
    group.children.some((child) => isActive(child.href));

  const toggleGroup = (name: string) =>
    setOpenGroups((prev) => ({ ...prev, [name]: !prev[name] }));

  const getInitials = (name: string) =>
    name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2);

  const renderNavItem = (item: NavItem) => {
    const Icon = item.icon;
    const active = isActive(item.href);

    if (collapsed) {
      return (
        <Tooltip key={item.name}>
          <TooltipTrigger asChild>
            <Link
              href={item.href}
              className={cn(
                'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                active
                  ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                  : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
              )}
            >
              <Icon className="h-5 w-5" />
            </Link>
          </TooltipTrigger>
          <TooltipContent side="right">
            <p>{item.name}</p>
          </TooltipContent>
        </Tooltip>
      );
    }

    return (
      <Link
        key={item.name}
        href={item.href}
        className={cn(
          'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
          active
            ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium'
            : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
        )}
      >
        <Icon className="h-5 w-5 shrink-0" />
        <span className="flex-1">{item.name}</span>
        {item.badge && item.badge > 0 && (
          <Badge variant="destructive" className="h-5 px-1.5">
            {item.badge}
          </Badge>
        )}
      </Link>
    );
  };

  const renderNavGroup = (group: NavGroup) => {
    const Icon = group.icon;
    const active = isGroupActive(group);
    const open = openGroups[group.name] ?? false;

    if (collapsed) {
      // Show group icon as a tooltip trigger, clicking navigates to first child
      return (
        <Tooltip key={group.name}>
          <TooltipTrigger asChild>
            <Link
              href={group.children[0]?.href ?? '/'}
              className={cn(
                'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                active
                  ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                  : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
              )}
            >
              <Icon className="h-5 w-5" />
            </Link>
          </TooltipTrigger>
          <TooltipContent side="right">
            <p>{group.name}</p>
          </TooltipContent>
        </Tooltip>
      );
    }

    return (
      <div key={group.name}>
        <button
          onClick={() => toggleGroup(group.name)}
          className={cn(
            'flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
            active
              ? 'text-sidebar-accent-foreground font-medium'
              : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
          )}
        >
          <Icon className="h-5 w-5 shrink-0" />
          <span className="flex-1 text-left">{group.name}</span>
          <ChevronDown
            className={cn('h-4 w-4 shrink-0 transition-transform', open && 'rotate-180')}
          />
        </button>
        {open && (
          <div className="ml-4 mt-0.5 space-y-0.5 border-l border-sidebar-border pl-2">
            {group.children.map((child) => {
              const ChildIcon = child.icon;
              const childActive = isActive(child.href);
              return (
                <Link
                  key={child.name}
                  href={child.href}
                  className={cn(
                    'flex items-center gap-3 rounded-lg px-3 py-1.5 text-sm transition-colors',
                    childActive
                      ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium'
                      : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                  )}
                >
                  <ChildIcon className="h-4 w-4 shrink-0" />
                  <span className="flex-1">{child.name}</span>
                </Link>
              );
            })}
          </div>
        )}
      </div>
    );
  };

  return (
    <TooltipProvider delayDuration={0}>
      <div className="flex h-screen bg-background">
        {/* Mobile overlay */}
        {mobileOpen && (
          <div
            className="fixed inset-0 z-40 bg-black/50 lg:hidden"
            onClick={() => setMobileOpen(false)}
          />
        )}

        {/* Sidebar */}
        <aside
          className={cn(
            'fixed inset-y-0 left-0 z-50 flex flex-col border-r bg-sidebar transition-all duration-300 lg:relative',
            collapsed ? 'w-16' : 'w-64',
            mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
          )}
        >
          {/* Logo */}
          <div className="flex h-16 items-center justify-between px-4">
            {!collapsed && (
              <Link href="/" className="flex items-center">
                <img
                  src="/images/tecc-banner.png"
                  alt="TECS"
                  className="h-9 object-contain"
                />
              </Link>
            )}
            <Button
              variant="ghost"
              size="icon"
              className="hidden lg:flex"
              onClick={() => setCollapsed(!collapsed)}
            >
              <ChevronLeft
                className={cn(
                  'h-5 w-5 transition-transform',
                  collapsed && 'rotate-180'
                )}
              />
            </Button>
          </div>

          <Separator />

          {/* Navigation */}
          <nav className="flex-1 space-y-0.5 p-2 overflow-y-auto">
            {navigation
              .filter((entry) => !isAgent || !(isNavGroup(entry) ? entry.permission : entry.permission))
              .map((entry) =>
                isNavGroup(entry)
                  ? renderNavGroup(entry)
                  : renderNavItem(entry)
              )}
          </nav>

          <Separator />

          {/* Bottom Navigation */}
          <div className="p-2 space-y-1">
            {bottomNav.map((item) => {
              const Icon = item.icon;
              const active = isActive(item.href);

              if (collapsed) {
                return (
                  <Tooltip key={item.name}>
                    <TooltipTrigger asChild>
                      <Link
                        href={item.href}
                        className={cn(
                          'flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                          active
                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                        )}
                      >
                        <Icon className="h-5 w-5" />
                      </Link>
                    </TooltipTrigger>
                    <TooltipContent side="right">
                      <p>{item.name}</p>
                    </TooltipContent>
                  </Tooltip>
                );
              }

              return (
                <Link
                  key={item.name}
                  href={item.href}
                  className={cn(
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                    active
                      ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium'
                      : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                  )}
                >
                  <Icon className="h-5 w-5 shrink-0" />
                  <span>{item.name}</span>
                </Link>
              );
            })}
          </div>

          <Separator />

          {/* User */}
          <div className="p-2">
            <div
              className={cn(
                'flex items-center gap-3 rounded-lg p-2',
                collapsed && 'justify-center'
              )}
            >
              <Avatar className="h-8 w-8">
                <AvatarImage src={auth?.user?.avatar_url} />
                <AvatarFallback className="bg-primary text-primary-foreground text-xs">
                  {auth?.user?.name ? getInitials(auth.user.name) : 'U'}
                </AvatarFallback>
              </Avatar>
              {!collapsed && (
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-sidebar-foreground truncate">
                    {auth?.user?.name || 'User'}
                  </p>
                  <p className="text-xs text-muted-foreground truncate">
                    {auth?.user?.role || 'Agent'}
                  </p>
                </div>
              )}
              {!collapsed && (
                <Link href="/logout" method="post" as="button">
                  <Button variant="ghost" size="icon" className="h-8 w-8">
                    <LogOut className="h-4 w-4" />
                  </Button>
                </Link>
              )}
            </div>
          </div>
        </aside>

        {/* Main Content */}
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Header */}
          <header className="flex h-16 items-center gap-4 border-b bg-background px-4 lg:px-6">
            <Button
              variant="ghost"
              size="icon"
              className="lg:hidden"
              onClick={() => setMobileOpen(true)}
            >
              <Menu className="h-5 w-5" />
            </Button>

            <div className="flex-1" />

            <Button variant="ghost" size="icon" className="relative">
              <Bell className="h-5 w-5" />
              <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-destructive text-[10px] text-destructive-foreground">
                3
              </span>
            </Button>
          </header>

          {/* Page Content */}
          <main className="flex-1 overflow-y-auto p-4 lg:p-6">{children}</main>
        </div>
      </div>
    </TooltipProvider>
  );
}
