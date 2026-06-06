import { PropsWithChildren, useEffect, useState, useMemo } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
  LayoutDashboard, Package, Users, Truck, ClipboardCheck, BarChart3,
  Settings, Bell, ChevronLeft, ChevronDown, LogOut, Menu, Phone,
  Recycle, UserCog, Headphones, Upload, MessageSquare, ShieldAlert,
  Shield, AlertOctagon, ScanLine, HelpCircle, Warehouse as WarehouseIcon,
  ShoppingCart, FileText, PackageCheck, Building2, TrendingUp,
  Store, BookUser, Search, ChevronRight, Home,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Separator } from '@/components/ui/separator';
import {
  Tooltip, TooltipContent, TooltipProvider, TooltipTrigger,
} from '@/components/ui/tooltip';
import { Badge } from '@/components/ui/badge';
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PageProps } from '@/types';
import CommandPalette from '@/components/CommandPalette';

/* ─── Role-based navigation ─── */
const ALL_STAFF = ['superadmin','admin','supervisor','finance','accounting','warehouse','agent'];
const ADMIN_ONLY = ['superadmin','admin'];
const OPS_ROLES = ['superadmin','admin','supervisor','warehouse'];
const INVENTORY_MATERIAL_ROLES = ['superadmin','admin','supervisor','warehouse','finance','accounting'];
const AGENT_ONLY = ['agent'];

interface NavItem {
  name: string;
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  badge?: number;
  roles?: string[];
  divider?: boolean;
}

interface NavGroup {
  name: string;
  icon: React.ComponentType<{ className?: string }>;
  roles?: string[];
  children: NavItem[];
}

type NavEntry = NavItem | NavGroup;

function isNavGroup(entry: NavEntry): entry is NavGroup {
  return 'children' in entry;
}

const FINANCE_ROLES = ['superadmin','admin','finance','accounting'];
const CRM_ROLES = ['superadmin','admin','supervisor','finance','accounting'];

/* ─── Breadcrumb map ─── */
const BREADCRUMB_MAP: Record<string, string> = {
  '/': 'Dashboard',
  '/shop': 'Shop',
  '/waybills': 'Waybills',
  '/waybills/scanner': 'Scanner',
  '/waybills/import': 'Import',
  '/waybills/claims': 'Claims',
  '/waybills/claims/beyond-sla': 'Beyond SLA',
  '/waybills/unknown': 'Unknown',
  '/agent/leads': 'My Leads',
  '/leads': 'Leads',
  '/crm/contacts': 'CRM Contacts',
  '/qc': 'QC Review',
  '/recycling/pool': 'Recycling',
  '/monitoring/dashboard': 'Monitoring',
  '/sales': 'Sales',
  '/agents/governance': 'Agents',
  '/sms': 'SMS',
  '/orders': 'Orders',
  '/reports': 'Reports',
  '/admin': 'Admin',
  '/inventory': 'Inventory',
  '/inventory/movements': 'Movements',
  '/inventory/adjustments': 'Stock Adjustments',
  '/inventory/supplies': 'Supplies',
  '/products': 'Products',
  '/warehouses': 'Warehouses',
  '/procurement/suppliers': 'Suppliers',
  '/procurement/requests': 'Purchase Requests',
  '/procurement/orders': 'Purchase Orders',
  '/procurement/receiving': 'Receiving',
  '/finance': 'Finance',
  '/finance/quickbooks': 'QuickBooks',
  '/finance/cost-of-goods': 'Cost of Goods',
  '/finance/invoices': 'Invoices',
  '/finance/supplier-invoices': 'Supplier Invoices',
  '/couriers': 'Couriers',
  '/tickets': 'Tickets',
  '/settings': 'Settings',
};

const navigation: NavEntry[] = [
  /* ── General ── */
  { name: 'Dashboard', href: '/', icon: LayoutDashboard, roles: ALL_STAFF },
  { name: 'Shop', href: '/shop', icon: Store, roles: ADMIN_ONLY },

  /* ── Operations ── */
  {
    name: 'Operations',
    icon: WarehouseIcon,
    roles: INVENTORY_MATERIAL_ROLES,
    children: [
      { name: 'Inventory Dashboard', href: '/inventory', icon: BarChart3, roles: OPS_ROLES },
      { name: 'Movements', href: '/inventory/movements', icon: Recycle, roles: OPS_ROLES },
      { name: 'Stock Adjustments', href: '/inventory/adjustments', icon: ClipboardCheck, roles: INVENTORY_MATERIAL_ROLES },
      { name: 'Supplies', href: '/inventory/supplies', icon: Package, roles: INVENTORY_MATERIAL_ROLES },
      { name: 'Products', href: '/products', icon: Package, roles: OPS_ROLES },
      { name: 'Warehouses', href: '/warehouses', icon: Building2, roles: OPS_ROLES },
      { name: 'QC Review', href: '/qc', icon: ClipboardCheck, roles: OPS_ROLES },
      { name: 'Orders', href: '/orders', icon: ClipboardCheck, roles: OPS_ROLES },
      { name: 'Couriers', href: '/couriers', icon: Truck, roles: OPS_ROLES },
    ],
  },

  /* ── Procurement ── */
  {
    name: 'Procurement',
    icon: ShoppingCart,
    roles: OPS_ROLES,
    children: [
      { name: 'Suppliers', href: '/procurement/suppliers', icon: Building2, roles: OPS_ROLES },
      { name: 'Purchase Requests', href: '/procurement/requests', icon: FileText, roles: OPS_ROLES },
      { name: 'Purchase Orders', href: '/procurement/orders', icon: ShoppingCart, roles: OPS_ROLES },
      { name: 'Receiving (GR)', href: '/procurement/receiving', icon: PackageCheck, roles: OPS_ROLES },
    ],
  },

  /* ── Commercial ── */
  {
    name: 'Commercial',
    icon: TrendingUp,
    roles: FINANCE_ROLES,
    children: [
      { name: 'Finance Overview', href: '/finance', icon: BarChart3, roles: FINANCE_ROLES },
      { name: 'Invoices', href: '/finance/invoices', icon: FileText, roles: FINANCE_ROLES },
      { name: 'Supplier Invoices', href: '/finance/supplier-invoices', icon: Building2, roles: FINANCE_ROLES },
      { name: 'Cost of Goods', href: '/finance/cost-of-goods', icon: Package, roles: FINANCE_ROLES },
      { name: 'QuickBooks', href: '/finance/quickbooks', icon: Building2, roles: FINANCE_ROLES },
      { name: 'Sales', href: '/sales', icon: TrendingUp, roles: ADMIN_ONLY },
      { name: 'Tickets', href: '/tickets', icon: Headphones, roles: ALL_STAFF },
    ],
  },

  /* ── CRM ── */
  {
    name: 'CRM',
    icon: BookUser,
    roles: CRM_ROLES,
    children: [
      { name: 'All Contacts', href: '/crm/contacts', icon: BookUser },
      { name: 'Customers', href: '/crm/contacts?type=customer', icon: Users },
      { name: 'Suppliers', href: '/crm/contacts?type=supplier', icon: Building2 },
      { name: 'Prospects', href: '/crm/contacts?type=prospect', icon: TrendingUp },
    ],
  },

  /* ── Logistics ── */
  {
    name: 'Logistics',
    icon: Truck,
    roles: ADMIN_ONLY,
    children: [
      { name: 'All Waybills', href: '/waybills', icon: Truck },
      { name: 'Scanner', href: '/waybills/scanner', icon: ScanLine },
      { name: 'Import', href: '/waybills/import', icon: Upload },
      { name: 'Claims', href: '/waybills/claims', icon: ShieldAlert },
      { name: 'Beyond SLA', href: '/waybills/claims/beyond-sla', icon: AlertOctagon },
      { name: 'Unknown', href: '/waybills/unknown', icon: HelpCircle },
      { name: 'Leads', href: '/leads', icon: Users, roles: ADMIN_ONLY },
      { name: 'My Leads', href: '/agent/leads', icon: Phone, roles: AGENT_ONLY },
    ],
  },

  /* ── System ── */
  {
    name: 'System',
    icon: Shield,
    roles: ADMIN_ONLY,
    children: [
      { name: 'Admin', href: '/admin', icon: Shield },
      { name: 'Agents', href: '/agents/governance', icon: UserCog },
      { name: 'Monitoring', href: '/monitoring/dashboard', icon: BarChart3 },
      { name: 'Reports', href: '/reports', icon: ClipboardCheck },
      { name: 'Recycling', href: '/recycling/pool', icon: Recycle },
      { name: 'SMS', href: '/sms', icon: MessageSquare },
    ],
  },
];

const bottomNav: NavItem[] = [
  { name: 'Settings', href: '/settings', icon: Settings, roles: ALL_STAFF },
];

export default function AppLayout({ children }: PropsWithChildren) {
  type SharedUser = PageProps['auth']['user'];
  const page = usePage<PageProps & { user?: SharedUser }>().props;
  const authUser = page.auth?.user ?? page.user ?? null;
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});
  const [commandPaletteOpen, setCommandPaletteOpen] = useState(false);

  const currentPath = typeof window !== 'undefined' ? window.location.pathname : '/';

  /* ── Breadcrumbs ── */
  const breadcrumbs = useMemo(() => {
    const crumbs: { label: string; href?: string }[] = [{ label: 'Home', href: '/' }];
    const pageLabel = BREADCRUMB_MAP[currentPath];
    if (pageLabel && currentPath !== '/') {
      crumbs.push({ label: pageLabel });
    }
    return crumbs;
  }, [currentPath]);

  /* ── Command Palette: Cmd+K ── */
  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        setCommandPaletteOpen((o) => !o);
      }
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, []);

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
    const theme = authUser?.theme ?? 'light';
    const html = document.documentElement;
    if (theme === 'dark') {
      html.classList.add('dark');
    } else if (theme === 'system') {
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      html.classList.toggle('dark', prefersDark);
    } else {
      html.classList.remove('dark');
    }
  }, [authUser?.theme]);

  const userRole = authUser?.role ?? null;

  const canSee = (entry: NavEntry) => {
    const allowed = isNavGroup(entry) ? entry.roles : entry.roles;
    if (!allowed || allowed.length === 0) return true;
    if (!userRole) return false;
    return allowed.includes(userRole);
  };

  const canSeeNavItem = (item: NavItem) => {
    if (!item.roles || item.roles.length === 0) return true;
    if (!userRole) return false;
    return item.roles.includes(userRole);
  };

  const visibleChildren = (group: NavGroup) =>
    group.children.filter((child) => canSeeNavItem(child));

  const isActive = (href: string) => {
    if (href === '/') return currentPath === '/';
    if (href === '/waybills') return currentPath === '/waybills';
    return currentPath.startsWith(href);
  };

  const isGroupActive = (group: NavGroup) =>
    visibleChildren(group).some((child) => child.href && isActive(child.href));

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
      const firstVisibleChild = visibleChildren(group).find((child) => !child.divider);
      // Show group icon as a tooltip trigger, clicking navigates to first child
      return (
        <Tooltip key={group.name}>
          <TooltipTrigger asChild>
            <Link
              href={firstVisibleChild?.href ?? '/'}
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
            {visibleChildren(group).map((child) => {
              if (child.divider) {
                return (
                  <div key={child.name} className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-sidebar-foreground/50">
                    {child.name}
                  </div>
                );
              }
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
              .filter((entry) => canSee(entry))
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
                <AvatarImage src={authUser?.avatar_url} />
                <AvatarFallback className="bg-primary text-primary-foreground text-xs">
                  {authUser?.name ? getInitials(authUser.name) : 'U'}
                </AvatarFallback>
              </Avatar>
              {!collapsed && (
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium text-sidebar-foreground truncate">
                    {authUser?.name || 'User'}
                  </p>
                  <p className="text-xs text-muted-foreground truncate">
                    {authUser?.role || 'No role'}
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

            {/* Breadcrumbs */}
            <nav className="hidden md:flex items-center gap-1 text-sm text-muted-foreground">
              {breadcrumbs.map((crumb, i) => (
                <span key={i} className="flex items-center gap-1">
                  {i > 0 && <ChevronRight className="h-3 w-3" />}
                  {crumb.href ? (
                    <Link href={crumb.href} className="hover:text-foreground transition-colors">
                      {i === 0 ? <Home className="h-3.5 w-3.5" /> : crumb.label}
                    </Link>
                  ) : (
                    <span className="font-medium text-foreground">{crumb.label}</span>
                  )}
                </span>
              ))}
            </nav>

            <div className="flex-1" />

            {/* Global Search Trigger */}
            <Button
              variant="outline"
              size="sm"
              className="hidden md:flex h-9 w-64 items-center justify-between rounded-md border bg-muted/50 px-3 text-sm text-muted-foreground shadow-none hover:bg-muted"
              onClick={() => setCommandPaletteOpen(true)}
            >
              <span className="flex items-center gap-2">
                <Search className="h-3.5 w-3.5" />
                Search...
              </span>
              <kbd className="pointer-events-none hidden h-5 select-none items-center gap-1 rounded border bg-muted px-1.5 font-mono text-[10px] font-medium opacity-100 sm:flex">
                <span className="text-xs">⌘K</span>
              </kbd>
            </Button>

            {/* Notifications */}
            <Button variant="ghost" size="icon" className="relative">
              <Bell className="h-5 w-5" />
              <span className="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-destructive text-[10px] text-destructive-foreground">
                3
              </span>
            </Button>

            {/* User Menu */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="gap-2 px-2">
                  <Avatar className="h-7 w-7">
                    <AvatarImage src={authUser?.avatar_url} />
                    <AvatarFallback className="bg-primary text-primary-foreground text-[10px]">
                      {authUser?.name ? getInitials(authUser.name) : 'U'}
                    </AvatarFallback>
                  </Avatar>
                  <span className="hidden sm:inline text-sm font-medium">{authUser?.name || 'User'}</span>
                  <ChevronDown className="h-3 w-3 text-muted-foreground" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                  <div className="flex flex-col space-y-1">
                    <p className="text-sm font-medium">{authUser?.name || 'User'}</p>
                    <p className="text-xs text-muted-foreground">{authUser?.email || ''}</p>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <Link href="/settings" className="cursor-pointer w-full">
                    <Settings className="mr-2 h-4 w-4" />
                    Settings
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <Link href="/logout" method="post" as="button" className="cursor-pointer w-full text-destructive focus:text-destructive">
                    <LogOut className="mr-2 h-4 w-4" />
                    Log out
                  </Link>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </header>

          {/* Page Content */}
          <main className="flex-1 overflow-y-auto p-4 lg:p-6">{children}</main>
        </div>
      </div>
      <CommandPalette open={commandPaletteOpen} onOpenChange={setCommandPaletteOpen} />
    </TooltipProvider>
  );
}
// cache-bust-1780725425
