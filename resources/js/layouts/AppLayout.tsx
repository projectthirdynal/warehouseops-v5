import { PropsWithChildren, useCallback, useEffect, useRef, useState, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { Link, usePage } from '@inertiajs/react';
import {
  LayoutDashboard,
  Package,
  Users,
  Truck,
  ClipboardCheck,
  BarChart3,
  Settings,
  ChevronDown,
  LogOut,
  Menu,
  Phone,
  Recycle,
  UserCog,
  Headphones,
  MessageSquare,
  ShieldAlert,
  Shield,
  AlertOctagon,
  ScanLine,
  HelpCircle,
  Warehouse as WarehouseIcon,
  ShoppingCart,
  FileText,
  PackageCheck,
  Building2,
  TrendingUp,
  Store,
  BookUser,
  Search,
  ChevronRight,
  Home,
  ArrowUpDown,
  Upload,
  CheckSquare,
  Bell,
  Skull,
  SlidersHorizontal,
  Inbox as InboxIcon,
  Facebook,
  Zap,
  Tags,
  Layers,
  GitBranch,
  KeyRound,
  Webhook,
  DollarSign,
  UsersRound,
  PackageSearch,
  RotateCcw,
  Bot,
  ScrollText,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PageProps } from '@/types';
import CommandPalette from '@/components/CommandPalette';
import { HotkeyCheatSheet } from '@/components/HotkeyCheatSheet';
import { useGlobalHotkeys } from '@/hooks/use-hotkeys';
import { toast } from 'sonner';

/* ─── Role-based navigation ─── */
const ALL_STAFF = [
  'superadmin',
  'admin',
  'supervisor',
  'finance',
  'accounting',
  'warehouse',
  'agent',
];
const ADMIN_ONLY = ['superadmin', 'admin'];
const OPS_ROLES = ['superadmin', 'admin', 'supervisor', 'warehouse'];
const INVENTORY_MATERIAL_ROLES = [
  'superadmin',
  'admin',
  'supervisor',
  'warehouse',
  'finance',
  'accounting',
];
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

const FINANCE_ROLES = ['superadmin', 'admin', 'finance', 'accounting'];

/* ─── Breadcrumb map ─── */
const BREADCRUMB_MAP: Record<string, string> = {
  '/': 'Dashboard',
  '/shop': 'Shop',
  '/shop/pos': 'POS',
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
  '/lead-pool': 'Lead Pool',
  '/telesales/import': 'Telesales Import',
  '/distribution': 'Distribution',
  '/distribution/analytics': 'Distribution Analytics',
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
  '/approvals': 'Approvals',
  '/finance': 'Finance',
  '/finance/quickbooks': 'QuickBooks',
  '/finance/cost-of-goods': 'Cost of Goods',
  '/finance/invoices': 'Invoices',
  '/finance/supplier-invoices': 'Supplier Invoices',
  '/couriers': 'Couriers',
  '/tickets': 'Tickets',
  '/settings': 'Settings',
  '/shop/inbox': 'Inbox',
  '/shop/orders': 'Orders',
  '/shop/customers': 'Customers',
  '/shop/duplicate-review': 'Identity Matching',
  '/shop/webhooks': 'Webhook Health',
  '/shop/meta-readiness': 'Token Health',
  '/shop/reply-templates': 'Saved Replies',
  '/shop/broadcast': 'Broadcast Rules',
  '/sales-dashboard': 'Sales Dashboard',
  '/waybills/returns': 'Returns',
  '/waybills/courier-analytics': 'Courier Analytics',
  '/finance/cod': 'COD Settlements',
  '/finance/commissions': 'Commissions',
};

const OPS_ADMIN_ROLES = ['superadmin', 'admin', 'supervisor'];
const SHOP_ROLES = ['superadmin', 'admin', 'supervisor'];
const CRM_NAV_ROLES = ['superadmin', 'admin', 'supervisor', 'finance', 'accounting'];
const REPORTS_ROLES = ['superadmin', 'admin', 'supervisor', 'finance', 'accounting', 'warehouse'];

const navigation: NavEntry[] = [
  /* ── Dashboard ── */
  { name: 'Dashboard', href: '/', icon: LayoutDashboard, roles: ALL_STAFF },

  /* ── Inbox ── */
  {
    name: 'Inbox',
    icon: InboxIcon,
    roles: SHOP_ROLES,
    children: [
      { name: 'All Conversations', href: '/shop/inbox', icon: MessageSquare, roles: SHOP_ROLES },
      {
        name: 'Assigned to Me',
        href: '/shop/inbox?filter=assigned',
        icon: UsersRound,
        roles: SHOP_ROLES,
      },
      { name: 'Unassigned', href: '/shop/inbox?filter=unassigned', icon: Users, roles: SHOP_ROLES },
      {
        name: 'Follow-Up',
        href: '/shop/inbox?filter=follow-up',
        icon: ClipboardCheck,
        roles: SHOP_ROLES,
      },
      {
        name: 'Resolved',
        href: '/shop/inbox?filter=resolved',
        icon: CheckSquare,
        roles: SHOP_ROLES,
      },
      { name: 'Spam', href: '/shop/inbox?filter=spam', icon: ShieldAlert, roles: SHOP_ROLES },
    ],
  },

  /* ── Orders ── */
  {
    name: 'Orders',
    icon: ClipboardCheck,
    roles: SHOP_ROLES,
    children: [
      { name: 'All Orders', href: '/shop/orders', icon: ClipboardCheck, roles: SHOP_ROLES },
      { name: 'Draft', href: '/shop/orders?status=draft', icon: FileText, roles: SHOP_ROLES },
      {
        name: 'Confirmed',
        href: '/shop/orders?status=confirmed',
        icon: CheckSquare,
        roles: SHOP_ROLES,
      },
      {
        name: 'For Packing',
        href: '/shop/orders?status=for_packing',
        icon: Package,
        roles: SHOP_ROLES,
      },
      { name: 'Shipped', href: '/shop/orders?status=shipped', icon: Truck, roles: SHOP_ROLES },
      {
        name: 'Delivered',
        href: '/shop/orders?status=delivered',
        icon: PackageCheck,
        roles: SHOP_ROLES,
      },
      {
        name: 'Cancelled',
        href: '/shop/orders?status=cancelled',
        icon: AlertOctagon,
        roles: SHOP_ROLES,
      },
      {
        name: 'Returned',
        href: '/shop/orders?status=returned',
        icon: RotateCcw,
        roles: SHOP_ROLES,
      },
    ],
  },

  /* ── Customers ── */
  {
    name: 'Customers',
    icon: Users,
    roles: CRM_NAV_ROLES,
    children: [
      { name: 'Customer Directory', href: '/shop/customers', icon: Users, roles: CRM_NAV_ROLES },
      {
        name: 'Identity Matching',
        href: '/shop/duplicate-review',
        icon: GitBranch,
        roles: CRM_NAV_ROLES,
      },
      {
        name: 'Risk Profiles',
        href: '/shop/customers?tab=risk',
        icon: ShieldAlert,
        roles: CRM_NAV_ROLES,
      },
      {
        name: 'Segments',
        href: '/shop/customers?tab=segments',
        icon: UsersRound,
        roles: CRM_NAV_ROLES,
      },
    ],
  },

  /* ── Products ── */
  {
    name: 'Products',
    icon: Package,
    roles: OPS_ROLES,
    children: [
      { name: 'Products', href: '/products', icon: Package, roles: OPS_ROLES },
      { name: 'Variants', href: '/products?tab=variants', icon: GitBranch, roles: OPS_ROLES },
      { name: 'Bundles', href: '/products?tab=bundles', icon: Layers, roles: OPS_ROLES },
      { name: 'Price Lists', href: '/products?tab=price-lists', icon: Tags, roles: OPS_ROLES },
    ],
  },

  /* ── Inventory ── */
  {
    name: 'Inventory',
    icon: WarehouseIcon,
    roles: INVENTORY_MATERIAL_ROLES,
    children: [
      {
        name: 'Stock Levels',
        href: '/inventory',
        icon: BarChart3,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      {
        name: 'Reservations',
        href: '/inventory?tab=reservations',
        icon: PackageSearch,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      {
        name: 'Movements',
        href: '/inventory/movements',
        icon: Recycle,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      { name: 'Warehouses', href: '/warehouses', icon: Building2, roles: OPS_ROLES },
      { name: 'Returns', href: '/waybills/returns', icon: RotateCcw, roles: OPS_ADMIN_ROLES },
      {
        name: 'Supplies',
        href: '/inventory/supplies',
        icon: Package,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      {
        name: 'Stock Adjustments',
        href: '/inventory/adjustments',
        icon: SlidersHorizontal,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      {
        name: 'Non-Moving',
        href: '/inventory/non-moving',
        icon: AlertOctagon,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      {
        name: 'Dead Stock',
        href: '/inventory/dead-stock',
        icon: Skull,
        roles: INVENTORY_MATERIAL_ROLES,
      },
    ],
  },

  /* ── Facebook ── */
  {
    name: 'Facebook',
    icon: Facebook,
    roles: ADMIN_ONLY,
    children: [
      {
        name: 'Connected Accounts',
        href: '/shop/facebook/connect',
        icon: Facebook,
        roles: ADMIN_ONLY,
      },
      { name: 'Connected Pages', href: '/shop', icon: Store, roles: ADMIN_ONLY },
      {
        name: 'Page Configuration',
        href: '/shop?tab=page-config',
        icon: Settings,
        roles: ADMIN_ONLY,
      },
      { name: 'Webhook Health', href: '/shop/webhooks', icon: Webhook, roles: ADMIN_ONLY },
      { name: 'Token Health', href: '/shop/meta-readiness', icon: KeyRound, roles: ADMIN_ONLY },
    ],
  },

  /* ── Automation ── */
  {
    name: 'Automation',
    icon: Zap,
    roles: ADMIN_ONLY,
    children: [
      {
        name: 'Rules',
        href: '/shop?tab=auto-assign',
        icon: SlidersHorizontal,
        roles: ADMIN_ONLY,
      },
      {
        name: 'Saved Replies',
        href: '/shop/reply-templates',
        icon: MessageSquare,
        roles: ADMIN_ONLY,
      },
      {
        name: 'Assignment Rules',
        href: '/shop?tab=rules',
        icon: UserCog,
        roles: ADMIN_ONLY,
      },
      { name: 'Broadcast Rules', href: '/shop?tab=broadcast', icon: Bot, roles: ADMIN_ONLY },
    ],
  },

  /* ── Reports ── */
  {
    name: 'Reports',
    icon: BarChart3,
    roles: REPORTS_ROLES,
    children: [
      { name: 'Sales', href: '/sales-dashboard', icon: TrendingUp, roles: OPS_ADMIN_ROLES },
      { name: 'Orders', href: '/reports?type=orders', icon: ClipboardCheck, roles: REPORTS_ROLES },
      { name: 'Agents', href: '/reports?type=agents', icon: Users, roles: OPS_ADMIN_ROLES },
      { name: 'Pages', href: '/reports?type=pages', icon: Store, roles: ADMIN_ONLY },
      {
        name: 'Inventory',
        href: '/reports?type=inventory',
        icon: Package,
        roles: INVENTORY_MATERIAL_ROLES,
      },
      { name: 'Courier', href: '/waybills/courier-analytics', icon: Truck, roles: OPS_ADMIN_ROLES },
    ],
  },

  /* ── Operational Tools (retained from existing system) ── */
  {
    name: 'Logistics',
    icon: Truck,
    roles: OPS_ADMIN_ROLES,
    children: [
      { name: 'All Waybills', href: '/waybills', icon: Truck, roles: OPS_ADMIN_ROLES },
      { name: 'Scanner', href: '/waybills/scanner', icon: ScanLine, roles: OPS_ADMIN_ROLES },
      { name: 'Import', href: '/waybills/import', icon: Upload, roles: OPS_ADMIN_ROLES },
      { name: 'Claims', href: '/waybills/claims', icon: ShieldAlert, roles: OPS_ADMIN_ROLES },
      {
        name: 'Beyond SLA',
        href: '/waybills/claims/beyond-sla',
        icon: AlertOctagon,
        roles: OPS_ADMIN_ROLES,
      },
      { name: 'Unknown', href: '/waybills/unknown', icon: HelpCircle, roles: OPS_ADMIN_ROLES },
      { name: 'Couriers', href: '/couriers', icon: Truck, roles: OPS_ADMIN_ROLES },
    ],
  },

  /* ── Procurement ── */
  {
    name: 'Procurement',
    icon: ShoppingCart,
    roles: OPS_ROLES,
    children: [
      { name: 'Suppliers', href: '/procurement/suppliers', icon: Building2, roles: OPS_ROLES },
      {
        name: 'Purchase Requests',
        href: '/procurement/requests',
        icon: FileText,
        roles: OPS_ROLES,
      },
      {
        name: 'Purchase Orders',
        href: '/procurement/orders',
        icon: ShoppingCart,
        roles: OPS_ROLES,
      },
      {
        name: 'Receiving (GR)',
        href: '/procurement/receiving',
        icon: PackageCheck,
        roles: OPS_ROLES,
      },
    ],
  },

  /* ── Finance ── */
  {
    name: 'Finance',
    icon: DollarSign,
    roles: FINANCE_ROLES,
    children: [
      { name: 'Finance Overview', href: '/finance', icon: BarChart3, roles: FINANCE_ROLES },
      { name: 'Invoices', href: '/finance/invoices', icon: FileText, roles: FINANCE_ROLES },
      {
        name: 'Supplier Invoices',
        href: '/finance/supplier-invoices',
        icon: Building2,
        roles: FINANCE_ROLES,
      },
      {
        name: 'Cost of Goods',
        href: '/finance/cost-of-goods',
        icon: Package,
        roles: FINANCE_ROLES,
      },
      { name: 'QuickBooks', href: '/finance/quickbooks', icon: Building2, roles: FINANCE_ROLES },
      { name: 'COD Settlements', href: '/finance/cod', icon: DollarSign, roles: FINANCE_ROLES },
      { name: 'Commissions', href: '/finance/commissions', icon: TrendingUp, roles: FINANCE_ROLES },
    ],
  },

  /* ── CRM ── */
  {
    name: 'CRM',
    icon: BookUser,
    roles: CRM_NAV_ROLES,
    children: [
      { name: 'All Contacts', href: '/crm/contacts', icon: BookUser, roles: CRM_NAV_ROLES },
      { name: 'Customers', href: '/crm/contacts?type=customer', icon: Users, roles: CRM_NAV_ROLES },
      {
        name: 'Suppliers',
        href: '/crm/contacts?type=supplier',
        icon: Building2,
        roles: CRM_NAV_ROLES,
      },
      {
        name: 'Prospects',
        href: '/crm/contacts?type=prospect',
        icon: TrendingUp,
        roles: CRM_NAV_ROLES,
      },
    ],
  },

  /* ── Leads & Distribution ── */
  {
    name: 'Leads',
    icon: Users,
    roles: OPS_ADMIN_ROLES,
    children: [
      { name: 'Lead Pool', href: '/lead-pool', icon: Users, roles: OPS_ADMIN_ROLES },
      { name: 'Distribution', href: '/distribution', icon: ArrowUpDown, roles: OPS_ADMIN_ROLES },
      {
        name: 'Distribution Analytics',
        href: '/distribution/analytics',
        icon: BarChart3,
        roles: OPS_ADMIN_ROLES,
      },
      { name: 'QC Review', href: '/qc', icon: ClipboardCheck, roles: OPS_ADMIN_ROLES },
      { name: 'Recycling', href: '/recycling/pool', icon: Recycle, roles: OPS_ADMIN_ROLES },
      { name: 'Telesales Import', href: '/telesales/import', icon: Upload, roles: OPS_ADMIN_ROLES },
      { name: 'My Leads', href: '/agent/leads', icon: Phone, roles: AGENT_ONLY },
    ],
  },

  /* ── System ── */
  {
    name: 'System',
    icon: Shield,
    roles: ADMIN_ONLY,
    children: [
      { name: 'Admin Panel', href: '/admin', icon: Shield, roles: ADMIN_ONLY },
      { name: 'Agents', href: '/agents/governance', icon: UserCog, roles: ADMIN_ONLY },
      { name: 'Monitoring', href: '/monitoring/dashboard', icon: BarChart3, roles: ADMIN_ONLY },
      {
        name: 'Approvals',
        href: '/approvals',
        icon: CheckSquare,
        roles: ['superadmin', 'admin', 'supervisor', 'finance', 'warehouse'],
      },
      { name: 'Tickets', href: '/tickets', icon: Headphones, roles: ALL_STAFF },
      { name: 'SMS', href: '/sms', icon: MessageSquare, roles: ADMIN_ONLY },
    ],
  },
];

const bottomNav: NavEntry[] = [
  {
    name: 'Settings',
    icon: Settings,
    roles: ALL_STAFF,
    children: [
      { name: 'Business', href: '/settings?tab=business', icon: Building2, roles: ADMIN_ONLY },
      { name: 'Shop', href: '/settings?tab=shop', icon: Store, roles: ADMIN_ONLY },
      { name: 'Users & Roles', href: '/admin', icon: UserCog, roles: ADMIN_ONLY },
      { name: 'Pages', href: '/settings?tab=pages', icon: Store, roles: ADMIN_ONLY },
      { name: 'Orders', href: '/settings?tab=orders', icon: ClipboardCheck, roles: ADMIN_ONLY },
      { name: 'Payments', href: '/settings?tab=payments', icon: DollarSign, roles: ADMIN_ONLY },
      { name: 'Courier', href: '/couriers', icon: Truck, roles: ADMIN_ONLY },
      { name: 'Notifications', href: '/settings?tab=notifications', icon: Bell, roles: ALL_STAFF },
      { name: 'Security', href: '/settings?tab=security', icon: Shield, roles: ALL_STAFF },
      { name: 'Audit Logs', href: '/settings?tab=audit-logs', icon: ScrollText, roles: ADMIN_ONLY },
      { name: 'Profile', href: '/settings', icon: Settings, roles: ALL_STAFF },
    ],
  },
];

export default function AppLayout({ children }: PropsWithChildren) {
  type SharedUser = PageProps['auth']['user'];
  const page = usePage<PageProps & { user?: SharedUser }>().props;
  const authUser = page.auth?.user ?? page.user ?? null;
  const [mobileOpen, setMobileOpen] = useState(false);
  const [hoveredGroup, setHoveredGroup] = useState<string | null>(null);
  const [commandPaletteOpen, setCommandPaletteOpen] = useState(false);
  const [cheatSheetOpen, setCheatSheetOpen] = useState(false);

  /* ── Global flash toasts (driven by Laravel ->with('success'|'error')) ── */
  useEffect(() => {
    const flash = page.flash as { success?: string; error?: string } | undefined;
    if (flash?.success) toast.success(flash.success);
    if (flash?.error) toast.error(flash.error);
  }, [page.flash]);

  /* ── Global hotkeys ── */
  useGlobalHotkeys(
    () => setCheatSheetOpen(true),
    () => setCommandPaletteOpen(true)
  );

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

  const getInitials = (name: string) =>
    name
      .split(' ')
      .map((n) => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);

  const renderNavItem = (item: NavItem) => {
    const Icon = item.icon;
    const active = isActive(item.href);

    return (
      <Tooltip key={item.name}>
        <TooltipTrigger asChild>
          <Link
            href={item.href}
            className={cn(
              'relative flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
              active
                ? 'bg-primary/10 text-primary'
                : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
            )}
          >
            {active && (
              <span className="absolute -left-2 top-1/2 h-6 w-1 -translate-y-1/2 rounded-full bg-primary" />
            )}
            <Icon className="h-5 w-5" />
            {item.badge && item.badge > 0 && (
              <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-medium text-white bg-destructive">
                {item.badge}
              </span>
            )}
          </Link>
        </TooltipTrigger>
        <TooltipContent side="right">
          <p>{item.name}</p>
        </TooltipContent>
      </Tooltip>
    );
  };

  const navGroupRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const [flyoutPos, setFlyoutPos] = useState<{ top: number; left: number } | null>(null);
  const closeTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const openGroup = useCallback((name: string) => {
    if (closeTimeoutRef.current) {
      clearTimeout(closeTimeoutRef.current);
      closeTimeoutRef.current = null;
    }
    const btn = navGroupRefs.current[name];
    if (btn) {
      const rect = btn.getBoundingClientRect();
      setFlyoutPos({ top: rect.top, left: rect.right + 4 });
    }
    setHoveredGroup(name);
  }, []);

  const closeGroup = useCallback(() => {
    if (closeTimeoutRef.current) {
      clearTimeout(closeTimeoutRef.current);
    }
    closeTimeoutRef.current = setTimeout(() => {
      setHoveredGroup(null);
      setFlyoutPos(null);
    }, 150);
  }, []);

  useEffect(() => {
    return () => {
      if (closeTimeoutRef.current) clearTimeout(closeTimeoutRef.current);
    };
  }, []);

  const renderNavGroup = (group: NavGroup) => {
    const Icon = group.icon;
    const active = isGroupActive(group);
    const isHovered = hoveredGroup === group.name;
    const children = visibleChildren(group);

    const flyout =
      isHovered && children.length > 0 && flyoutPos
        ? createPortal(
            <div
              className="fixed z-[9999] w-56 origin-left animate-in fade-in-0 zoom-in-95 slide-in-from-left-1 rounded-xl border bg-popover p-2 shadow-lg duration-150"
              style={{ top: flyoutPos.top, left: flyoutPos.left }}
              onMouseEnter={() => openGroup(group.name)}
              onMouseLeave={closeGroup}
            >
              <p className="mb-1 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {group.name}
              </p>
              {children.map((child) => {
                if (child.divider) {
                  return (
                    <div
                      key={child.name}
                      className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/50"
                    >
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
                    onClick={closeGroup}
                    className={cn(
                      'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors',
                      childActive
                        ? 'bg-primary/10 text-primary font-medium'
                        : 'text-popover-foreground hover:bg-accent hover:text-accent-foreground'
                    )}
                  >
                    <ChildIcon className="h-4 w-4 shrink-0" />
                    <span className="flex-1">{child.name}</span>
                  </Link>
                );
              })}
            </div>,
            document.body
          )
        : null;

    return (
      <div key={group.name}>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              ref={(el) => {
                navGroupRefs.current[group.name] = el;
              }}
              onMouseEnter={() => openGroup(group.name)}
              onMouseLeave={closeGroup}
              onClick={() => (isHovered ? closeGroup() : openGroup(group.name))}
              className={cn(
                'relative flex h-10 w-10 items-center justify-center rounded-lg transition-colors',
                active
                  ? 'bg-primary/10 text-primary'
                  : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
              )}
            >
              {active && (
                <span className="absolute -left-2 top-1/2 h-6 w-1 -translate-y-1/2 rounded-full bg-primary" />
              )}
              <Icon className="h-5 w-5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="right">
            <p>{group.name}</p>
          </TooltipContent>
        </Tooltip>
        {flyout}
      </div>
    );
  };

  return (
    <TooltipProvider delayDuration={0}>
      <div className="flex h-screen bg-background">
        {/* Mobile overlay */}
        {mobileOpen && (
          <div
            className="fixed inset-0 z-40 bg-ink/80 lg:hidden"
            onClick={() => setMobileOpen(false)}
          />
        )}

        {/* Sidebar — Icon Rail */}
        <aside
          className={cn(
            'fixed inset-y-0 left-0 z-50 flex w-16 flex-col items-center border-r bg-sidebar py-3 lg:relative',
            mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
          )}
        >
          {/* Logo */}
          <Link href="/" className="mb-3 flex h-10 w-10 items-center justify-center">
            <img
              src="/images/tecc-banner.png"
              alt="TECS"
              className="h-8 w-8 rounded-lg object-cover"
            />
          </Link>

          {/* Navigation */}
          <nav className="flex flex-1 flex-col items-center gap-1 overflow-visible py-2 sidebar-nav">
            {navigation
              .filter((entry) => canSee(entry))
              .map((entry) => (isNavGroup(entry) ? renderNavGroup(entry) : renderNavItem(entry)))}
          </nav>

          {/* Bottom Navigation */}
          <div className="flex flex-col items-center gap-1 pt-2">
            {bottomNav
              .filter((entry) => canSee(entry))
              .map((entry) => (isNavGroup(entry) ? renderNavGroup(entry) : renderNavItem(entry)))}
          </div>

          {/* User Avatar */}
          <Tooltip>
            <TooltipTrigger asChild>
              <Link href="/logout" method="post" as="button">
                <Avatar className="mt-2 h-8 w-8">
                  <AvatarImage src={authUser?.avatar_url} />
                  <AvatarFallback className="bg-primary text-primary-foreground text-xs">
                    {authUser?.name ? getInitials(authUser.name) : 'U'}
                  </AvatarFallback>
                </Avatar>
              </Link>
            </TooltipTrigger>
            <TooltipContent side="right">
              <p>{authUser?.name || 'User'} — Logout</p>
            </TooltipContent>
          </Tooltip>
        </aside>

        {/* Main Content */}
        <div className="flex flex-1 flex-col overflow-hidden">
          {/* Header */}
          <header className="flex h-14 items-center gap-3 border-b bg-card px-3 lg:px-4 shadow-card">
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
              className="hidden md:flex h-8 w-56 items-center justify-between rounded-lg border bg-muted/50 px-3 text-sm text-muted-foreground shadow-none hover:bg-muted"
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
            <NotificationBell />

            {/* User Menu */}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" className="gap-1.5 px-2">
                  <Avatar className="h-6 w-6">
                    <AvatarImage src={authUser?.avatar_url} />
                    <AvatarFallback className="bg-primary text-primary-foreground text-[10px]">
                      {authUser?.name ? getInitials(authUser.name) : 'U'}
                    </AvatarFallback>
                  </Avatar>
                  <span className="hidden lg:inline text-sm font-medium">
                    {authUser?.name || 'User'}
                  </span>
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
                  <Link
                    href="/logout"
                    method="post"
                    as="button"
                    className="cursor-pointer w-full text-destructive focus:text-destructive"
                  >
                    <LogOut className="mr-2 h-4 w-4" />
                    Log out
                  </Link>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </header>

          {/* Page Content */}
          <main className="flex-1 overflow-y-auto p-3 lg:p-5">{children}</main>
        </div>
      </div>
      <CommandPalette open={commandPaletteOpen} onOpenChange={setCommandPaletteOpen} />
      <HotkeyCheatSheet open={cheatSheetOpen} onOpenChange={setCheatSheetOpen} />
    </TooltipProvider>
  );
}
interface AppNotification {
  id: string;
  type: string;
  title: string;
  message: string;
  url: string | null;
  read: boolean;
  created_at: string;
}

function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const panelRef = useRef<HTMLDivElement>(null);
  const networkErrorRef = useRef(false);

  const fetchNotifications = useCallback(async () => {
    if (networkErrorRef.current) return;
    try {
      const res = await fetch('/api/notifications', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (res.ok) {
        const data = await res.json();
        setNotifications(data.notifications ?? []);
        setUnreadCount(data.unread_count ?? 0);
      }
    } catch {
      networkErrorRef.current = true;
    }
  }, []);

  useEffect(() => {
    fetchNotifications();
    const interval = setInterval(fetchNotifications, 30000);
    return () => clearInterval(interval);
  }, [fetchNotifications]);

  useEffect(() => {
    const handleClick = (e: MouseEvent) => {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    if (open) document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

  async function markRead(id: string) {
    await fetch(`/api/notifications/${id}/read`, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN':
          (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '',
      },
      credentials: 'same-origin',
    });
    setNotifications((prev) => prev.map((n) => (n.id === id ? { ...n, read: true } : n)));
    setUnreadCount((prev) => Math.max(0, prev - 1));
  }

  async function markAllRead() {
    await fetch('/api/notifications/read-all', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN':
          (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content ?? '',
      },
      credentials: 'same-origin',
    });
    setNotifications((prev) => prev.map((n) => ({ ...n, read: true })));
    setUnreadCount(0);
  }

  const typeIcon: Record<string, string> = {
    pr_submitted: '📋',
    pr_decided: '✅',
    adjustment_submitted: '⚖️',
    adjustment_decided: '📦',
  };

  return (
    <div className="relative" ref={panelRef}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="relative inline-flex items-center justify-center rounded-full p-2 hover:bg-accent transition-colors"
        aria-label={`${unreadCount} unread notifications`}
      >
        <Bell className="h-5 w-5 text-muted-foreground" />
        {unreadCount > 0 && (
          <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-medium text-white bg-destructive">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 top-10 z-50 w-80 rounded-xl border bg-popover shadow-xl">
          <div className="flex items-center justify-between border-b px-4 py-3">
            <span className="text-sm font-semibold">Notifications</span>
            {unreadCount > 0 && (
              <button onClick={markAllRead} className="text-xs text-primary hover:underline">
                Mark all read
              </button>
            )}
          </div>
          <div className="max-h-96 overflow-y-auto divide-y">
            {notifications.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">No notifications yet</p>
            ) : (
              notifications.map((n) => (
                <div
                  key={n.id}
                  className={`flex gap-3 px-4 py-3 cursor-pointer hover:bg-muted/50 transition-colors ${!n.read ? 'bg-primary/5' : ''}`}
                  onClick={() => {
                    markRead(n.id);
                    if (n.url) window.location.href = n.url;
                    setOpen(false);
                  }}
                >
                  <span className="mt-0.5 text-lg">{typeIcon[n.type] ?? '🔔'}</span>
                  <div className="flex-1 min-w-0">
                    <p
                      className={`text-sm ${!n.read ? 'font-semibold' : 'font-medium'} leading-tight`}
                    >
                      {n.title}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground line-clamp-2">{n.message}</p>
                    <p className="mt-1 text-[10px] text-muted-foreground">{n.created_at}</p>
                  </div>
                  {!n.read && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" />}
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// cache-bust-1781032200
