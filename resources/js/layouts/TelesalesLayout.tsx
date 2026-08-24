import { PropsWithChildren, useMemo, useState } from 'react';
import type { ComponentType } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import {
  Activity,
  BarChart3,
  Bell,
  Boxes,
  CheckCircle2,
  ChevronDown,
  ClipboardList,
  FilePlus2,
  FolderOpen,
  Gift,
  Headphones,
  Home,
  Import,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageSearch,
  Settings,
  ShoppingBag,
  TrendingUp,
  UserRound,
  Users,
  X,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PageProps } from '@/types';

type Role = PageProps['auth']['user']['role'];

type NavItem = {
  label: string;
  href: string;
  icon: ComponentType<{ className?: string }>;
  roles?: Role[];
  exact?: boolean;
};

type NavSection = {
  label?: string;
  items: NavItem[];
};

const MANAGER_ROLES: Role[] = ['superadmin', 'admin', 'supervisor', 'teamleader'];
const ADMIN_ROLES: Role[] = ['superadmin', 'admin'];

const navSections: NavSection[] = [
  {
    items: [
      {
        label: 'Dashboard',
        href: '/telesales',
        icon: LayoutDashboard,
        roles: MANAGER_ROLES,
        exact: true,
      },
    ],
  },
  {
    label: 'Leads',
    items: [
      { label: 'My Leads', href: '/agent/leads', icon: UserRound, roles: ['agent'] },
      {
        label: 'Lead Inventory',
        href: '/telesales/inventory',
        icon: PackageSearch,
        roles: MANAGER_ROLES,
      },
      {
        label: 'Pool Requests',
        href: '/telesales/pool-requests',
        icon: FilePlus2,
        roles: MANAGER_ROLES,
      },
      { label: 'Lead Pools', href: '/telesales/pools', icon: FolderOpen, roles: MANAGER_ROLES },
    ],
  },
  {
    label: 'Sales',
    items: [
      { label: 'Sales', href: '/sales', icon: TrendingUp, roles: MANAGER_ROLES },
      { label: 'Orders', href: '/telesales/orders', icon: ShoppingBag, roles: MANAGER_ROLES },
      {
        label: 'Customers',
        href: '/crm/contacts?type=customer',
        icon: Users,
        roles: MANAGER_ROLES,
      },
    ],
  },
  {
    label: 'Team',
    items: [
      { label: 'Agents', href: '/agents/governance', icon: Users, roles: ADMIN_ROLES },
      {
        label: 'Performance',
        href: '/distribution/analytics',
        icon: Activity,
        roles: MANAGER_ROLES,
      },
    ],
  },
  {
    label: 'Analytics',
    items: [
      { label: 'Reports', href: '/reports', icon: BarChart3, roles: MANAGER_ROLES },
      {
        label: 'Distribution',
        href: '/distribution/analytics',
        icon: ClipboardList,
        roles: MANAGER_ROLES,
      },
    ],
  },
  {
    label: 'Admin',
    items: [
      {
        label: 'Pool Approvals',
        href: '/telesales/pool-approvals',
        icon: CheckCircle2,
        roles: ADMIN_ROLES,
      },
      {
        label: 'Prices & Remarks',
        href: '/telesales/promos/price-remarks',
        icon: Gift,
        roles: ADMIN_ROLES,
      },
      { label: 'Lead Import', href: '/telesales/import', icon: Import, roles: MANAGER_ROLES },
    ],
  },
];

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('');
}

function isActive(currentUrl: string, item: NavItem): boolean {
  const currentPath = currentUrl.split('?')[0];
  const itemPath = item.href.split('?')[0];

  if (item.exact) return currentPath === itemPath;
  return currentPath === itemPath || currentPath.startsWith(`${itemPath}/`);
}

function pageTitle(currentUrl: string): string {
  const currentPath = currentUrl.split('?')[0];

  const titles: Array<[string, string]> = [
    ['/telesales/pool-approvals', 'Pool Approvals'],
    ['/telesales/pool-requests/create', 'Create Pool Request'],
    ['/telesales/pool-requests', 'Pool Requests'],
    ['/telesales/inventory', 'Lead Inventory'],
    ['/telesales/pools', 'Lead Pools'],
    ['/telesales/promos/price-remarks', 'Prices & Remarks'],
    ['/telesales/import', 'Lead Import'],
    ['/telesales/orders', 'Orders'],
    ['/telesales', 'Dashboard'],
  ];

  return (
    titles.find(([path]) => currentPath === path || currentPath.startsWith(`${path}/`))?.[1] ??
    'Telesales'
  );
}

export default function TelesalesLayout({ children }: PropsWithChildren) {
  const page = usePage<PageProps>();
  const [mobileOpen, setMobileOpen] = useState(false);
  const user = page.props.auth.user;
  const currentUrl = page.url;

  const sections = useMemo(
    () =>
      navSections
        .map((section) => ({
          ...section,
          items: section.items.filter((item) => !item.roles || item.roles.includes(user.role)),
        }))
        .filter((section) => section.items.length > 0),
    [user.role]
  );

  const sidebar = (
    <div className="flex h-full flex-col bg-white">
      <div className="flex h-[74px] items-center gap-3 border-b border-slate-200 px-5">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm shadow-blue-600/25">
          <Headphones className="h-5 w-5" />
        </div>
        <div className="min-w-0">
          <p className="truncate text-[17px] font-extrabold tracking-wide text-blue-700">
            TELESALES
          </p>
          <p className="-mt-0.5 truncate text-xs font-medium text-slate-500">WarehouseOps</p>
        </div>
      </div>

      <nav className="flex-1 overflow-y-auto px-3 py-4">
        <div className="space-y-5">
          {sections.map((section, sectionIndex) => (
            <div key={section.label ?? sectionIndex}>
              {section.label && (
                <p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.13em] text-slate-400">
                  {section.label}
                </p>
              )}
              <div className="space-y-1">
                {section.items.map((item) => {
                  const active = isActive(currentUrl, item);
                  const Icon = item.icon;
                  return (
                    <Link
                      key={`${item.label}-${item.href}`}
                      href={item.href}
                      onClick={() => setMobileOpen(false)}
                      className={cn(
                        'group flex min-h-10 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                        active
                          ? 'bg-blue-50 text-blue-700'
                          : 'text-slate-600 hover:bg-slate-50 hover:text-slate-950'
                      )}
                    >
                      <Icon
                        className={cn(
                          'h-[18px] w-[18px] shrink-0',
                          active ? 'text-blue-600' : 'text-slate-500 group-hover:text-slate-700'
                        )}
                      />
                      <span className="truncate">{item.label}</span>
                    </Link>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      </nav>

      <div className="border-t border-slate-200 p-3">
        <Link
          href="/"
          className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-900"
        >
          <Home className="h-[18px] w-[18px]" />
          Back to WarehouseOps
        </Link>
      </div>
    </div>
  );

  return (
    <div className="min-h-screen bg-[#f7f9fc] text-slate-950">
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-[232px] border-r border-slate-200 lg:block">
        {sidebar}
      </aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            aria-label="Close navigation"
            className="absolute inset-0 bg-slate-950/30 backdrop-blur-[1px]"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="relative h-full w-[280px] max-w-[85vw] border-r border-slate-200 bg-white shadow-2xl">
            <button
              type="button"
              className="absolute right-3 top-4 z-10 rounded-md p-2 text-slate-500 hover:bg-slate-100"
              onClick={() => setMobileOpen(false)}
              aria-label="Close sidebar"
            >
              <X className="h-5 w-5" />
            </button>
            {sidebar}
          </aside>
        </div>
      )}

      <div className="lg:pl-[232px]">
        <header className="sticky top-0 z-30 flex h-[74px] items-center justify-between border-b border-slate-200 bg-white/95 px-4 backdrop-blur md:px-6 lg:px-8">
          <div className="flex min-w-0 items-center gap-3">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="lg:hidden"
              onClick={() => setMobileOpen(true)}
            >
              <Menu className="h-5 w-5" />
            </Button>
            <div>
              <h1 className="truncate text-xl font-bold tracking-tight text-slate-950 md:text-2xl">
                {pageTitle(currentUrl)}
              </h1>
            </div>
          </div>

          <div className="flex items-center gap-1 sm:gap-2">
            <button
              type="button"
              className="relative flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-900"
              aria-label="Notifications"
            >
              <Bell className="h-5 w-5" />
            </button>

            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <button className="flex items-center gap-2 rounded-xl p-1.5 text-left transition-colors hover:bg-slate-50 sm:pr-2">
                  <Avatar className="h-9 w-9 border border-slate-200">
                    <AvatarImage src={user.avatar_url} alt={user.name} />
                    <AvatarFallback className="bg-slate-100 text-xs font-semibold text-slate-700">
                      {initials(user.name)}
                    </AvatarFallback>
                  </Avatar>
                  <div className="hidden min-w-0 sm:block">
                    <p className="max-w-36 truncate text-sm font-semibold text-slate-900">
                      {user.name}
                    </p>
                    <p className="text-[11px] capitalize text-slate-500">
                      {user.role.replace('_', ' ')}
                    </p>
                  </div>
                  <ChevronDown className="hidden h-4 w-4 text-slate-400 sm:block" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel>
                  <div className="space-y-0.5">
                    <p>{user.name}</p>
                    <p className="text-xs font-normal text-muted-foreground">{user.email}</p>
                  </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                  <Link href="/settings" className="cursor-pointer">
                    <Settings className="mr-2 h-4 w-4" /> Settings
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                  <Link href="/" className="cursor-pointer">
                    <Boxes className="mr-2 h-4 w-4" /> WarehouseOps
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="cursor-pointer text-red-600 focus:text-red-600"
                  onClick={() => router.post('/logout')}
                >
                  <LogOut className="mr-2 h-4 w-4" /> Logout
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        <main className="min-h-[calc(100vh-74px)]">{children}</main>
      </div>
    </div>
  );
}
