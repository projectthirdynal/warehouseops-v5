import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard,
  Package,
  Users,
  Truck,
  ClipboardCheck,
  BarChart3,
  Settings,
  Phone,
  Recycle,
  UserCog,
  MessageSquare,
  Shield,
  FileText,
  ShoppingCart,
  PackageCheck,
  Building2,
  TrendingUp,
  Store,
  BookUser,
  ScanLine,
  Upload,
  ShieldAlert,
  AlertOctagon,
  HelpCircle,
  ChevronRight,
  Search,
  Clock,
  Zap,
  Plus,
  Loader2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/* ── Supply record search ── */
interface SupplyHit {
  id: number;
  sku: string;
  name: string;
  stock_status: string;
  is_active: boolean;
}

const STATUS_CLS: Record<string, string> = {
  MOVING: 'bg-success/20 text-success',
  NON_MOVING: 'bg-warning/20 text-warning',
  DEAD: 'bg-destructive/20 text-destructive',
  OUT_OF_STOCK: 'bg-ink text-muted-foreground',
};

function useSupplySearch(q: string) {
  const [hits, setHits] = useState<SupplyHit[]>([]);
  const [loading, setLoading] = useState(false);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (q.length < 2) {
      setHits([]);
      return;
    }
    if (timerRef.current) clearTimeout(timerRef.current);
    const controller = new AbortController();
    timerRef.current = setTimeout(() => {
      setLoading(true);
      fetch(`/inventory/supplies/search?q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      })
        .then((r) => r.json())
        .then((data: SupplyHit[]) => {
          setHits(Array.isArray(data) ? data : []);
          setLoading(false);
        })
        .catch((err) => {
          if (err.name !== 'AbortError') setLoading(false);
        });
    }, 280);
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
      controller.abort();
    };
  }, [q]);

  return { hits, loading };
}

/* ── Recent Items localStorage ── */
const RECENTS_KEY = 'cmdp_recents_v1';
const MAX_RECENTS = 5;
function getRecents(): string[] {
  try {
    return JSON.parse(localStorage.getItem(RECENTS_KEY) || '[]');
  } catch {
    return [];
  }
}
function pushRecent(href: string) {
  const prev = getRecents();
  const next = [href, ...prev.filter((h) => h !== href)].slice(0, MAX_RECENTS);
  localStorage.setItem(RECENTS_KEY, JSON.stringify(next));
}

/* ── Types ── */
interface NavItem {
  id?: string;
  name: string;
  href: string;
  icon: LucideIcon;
  section: string;
  keywords?: string[];
  action?: boolean;
}

const allNavItems: NavItem[] = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard, section: 'General' },
  { name: 'Shop', href: '/shop', icon: Store, section: 'General' },
  {
    name: 'All Waybills',
    href: '/waybills',
    icon: Truck,
    section: 'Logistics',
    keywords: ['waybill', 'shipping'],
  },
  { name: 'Scanner', href: '/waybills/scanner', icon: ScanLine, section: 'Logistics' },
  { name: 'Import', href: '/waybills/import', icon: Upload, section: 'Logistics' },
  { name: 'Claims', href: '/waybills/claims', icon: ShieldAlert, section: 'Logistics' },
  {
    name: 'Beyond SLA',
    href: '/waybills/claims/beyond-sla',
    icon: AlertOctagon,
    section: 'Logistics',
  },
  { name: 'Unknown', href: '/waybills/unknown', icon: HelpCircle, section: 'Logistics' },
  { name: 'My Leads', href: '/agent/leads', icon: Phone, section: 'Logistics' },
  { name: 'Leads', href: '/leads', icon: Users, section: 'Logistics' },
  { name: 'All Contacts', href: '/crm/contacts', icon: BookUser, section: 'CRM' },
  { name: 'Customers', href: '/crm/contacts?type=customer', icon: Users, section: 'CRM' },
  { name: 'Suppliers', href: '/crm/contacts?type=supplier', icon: Building2, section: 'CRM' },
  { name: 'Prospects', href: '/crm/contacts?type=prospect', icon: TrendingUp, section: 'CRM' },
  { name: 'QC Review', href: '/qc', icon: ClipboardCheck, section: 'System' },
  { name: 'Recycling', href: '/recycling/pool', icon: Recycle, section: 'System' },
  { name: 'Monitoring', href: '/monitoring/dashboard', icon: BarChart3, section: 'System' },
  { name: 'Sales', href: '/sales', icon: TrendingUp, section: 'Commercial' },
  { name: 'Agents', href: '/agents/governance', icon: UserCog, section: 'System' },
  { name: 'SMS', href: '/sms', icon: MessageSquare, section: 'System' },
  { name: 'Orders', href: '/orders', icon: ClipboardCheck, section: 'Commercial' },
  { name: 'Reports', href: '/reports', icon: ClipboardCheck, section: 'System' },
  { name: 'Admin', href: '/admin', icon: Shield, section: 'System' },
  { name: 'Inventory Dashboard', href: '/inventory', icon: BarChart3, section: 'Operations' },
  { name: 'Movements', href: '/inventory/movements', icon: Recycle, section: 'Operations' },
  {
    name: 'Stock Adjustments',
    href: '/inventory/adjustments',
    icon: ClipboardCheck,
    section: 'Operations',
  },
  { name: 'Supplies', href: '/inventory/supplies', icon: Package, section: 'Operations' },
  { name: 'Products', href: '/products', icon: Package, section: 'Operations' },
  { name: 'Warehouses', href: '/warehouses', icon: Building2, section: 'Operations' },
  {
    name: 'Procurement Suppliers',
    href: '/procurement/suppliers',
    icon: Building2,
    section: 'Procurement',
  },
  {
    name: 'Purchase Requests',
    href: '/procurement/requests',
    icon: FileText,
    section: 'Procurement',
  },
  {
    name: 'Purchase Orders',
    href: '/procurement/orders',
    icon: ShoppingCart,
    section: 'Procurement',
  },
  {
    name: 'Receiving (GR)',
    href: '/procurement/receiving',
    icon: PackageCheck,
    section: 'Procurement',
  },
  { name: 'Finance Overview', href: '/finance', icon: BarChart3, section: 'Commercial' },
  { name: 'QuickBooks', href: '/finance/quickbooks', icon: Building2, section: 'Commercial' },
  { name: 'Cost of Goods', href: '/finance/cost-of-goods', icon: Package, section: 'Commercial' },
  { name: 'Invoices', href: '/finance/invoices', icon: FileText, section: 'Commercial' },
  {
    name: 'Supplier Invoices',
    href: '/finance/supplier-invoices',
    icon: Building2,
    section: 'Commercial',
  },
  { name: 'Couriers', href: '/couriers', icon: Truck, section: 'Logistics' },
  { name: 'Tickets', href: '/tickets', icon: Phone, section: 'Commercial' },
  { name: 'Settings', href: '/settings', icon: Settings, section: 'System' },

  /* ── Action Verbs ── */
  {
    id: 'act-new-wb',
    name: 'Create Waybill',
    href: '/waybills/create',
    icon: Plus,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-new-inv',
    name: 'Create Invoice',
    href: '/finance/invoices/create',
    icon: Plus,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-new-ord',
    name: 'Create Order',
    href: '/orders/create',
    icon: Plus,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-new-contact',
    name: 'Create Contact',
    href: '/crm/contacts/create',
    icon: Plus,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-new-prod',
    name: 'Create Product',
    href: '/products/create',
    icon: Plus,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-import',
    name: 'Import Waybills',
    href: '/waybills/import',
    icon: Upload,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-scan',
    name: 'Open Scanner',
    href: '/waybills/scanner',
    icon: ScanLine,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-reports',
    name: 'View Reports',
    href: '/reports',
    icon: BarChart3,
    section: 'Actions',
    action: true,
  },
  {
    id: 'act-settings',
    name: 'Open Settings',
    href: '/settings',
    icon: Settings,
    section: 'Actions',
    action: true,
  },
];

interface CommandPaletteProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/* ── Scope filter parser ── */
function parseQuery(raw: string) {
  const trimmed = raw.trim();
  if (!trimmed) return { scope: null, q: '' };
  const m = trimmed.match(/^in:(\w+)\s+(.*)$/i);
  if (m) return { scope: m[1].toLowerCase(), q: m[2].trim().toLowerCase() };
  return { scope: null, q: trimmed.toLowerCase() };
}

export default function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const { scope, q } = useMemo(() => parseQuery(query), [query]);

  /* Live supply search — fires when q ≥ 2 chars and no scope filter */
  const { hits: supplyHits, loading: supplyLoading } = useSupplySearch(
    !scope && q.length >= 2 ? q : ''
  );

  /* Recent items (hydrated from localStorage) */
  const [recents, setRecents] = useState<string[]>([]);
  useEffect(() => {
    if (open) setRecents(getRecents());
  }, [open]);

  /* Filtered results */
  const filtered = useMemo(() => {
    if (!q && !scope) return allNavItems;
    return allNavItems.filter((item) => {
      if (scope && item.section.toLowerCase() !== scope) return false;
      if (!q) return true;
      const text = `${item.name} ${item.section} ${(item.keywords ?? []).join(' ')}`.toLowerCase();
      return text.includes(q);
    });
  }, [q, scope]);

  /* Build display list: recents first when empty query */
  const displayList = useMemo(() => {
    if (q || scope) return filtered;
    const recentItems = recents
      .map((href) => allNavItems.find((i) => i.href === href))
      .filter(Boolean) as NavItem[];
    const others = filtered.filter((i) => !recents.includes(i.href));
    return [...recentItems, ...others];
  }, [q, scope, filtered, recents]);

  const grouped = useMemo(() => {
    const map = new Map<string, NavItem[]>();
    displayList.forEach((item) => {
      const list = map.get(item.section) ?? [];
      list.push(item);
      map.set(item.section, list);
    });
    return Array.from(map.entries());
  }, [displayList]);

  const navigate = useCallback(
    (href: string) => {
      pushRecent(href);
      onOpenChange(false);
      setQuery('');
      setSelectedIndex(0);
      router.visit(href);
    },
    [onOpenChange]
  );

  useEffect(() => {
    setSelectedIndex(0);
  }, [query]);
  useEffect(() => {
    if (open && inputRef.current) setTimeout(() => inputRef.current?.focus(), 50);
  }, [open]);

  useEffect(() => {
    const handle = (e: KeyboardEvent) => {
      if (!open) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setSelectedIndex((i) => (i + 1) % displayList.length);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setSelectedIndex((i) => (i - 1 + displayList.length) % displayList.length);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const item = displayList[selectedIndex];
        if (item) navigate(item.href);
      } else if (e.key === 'Escape') {
        onOpenChange(false);
      }
    };
    window.addEventListener('keydown', handle);
    return () => window.removeEventListener('keydown', handle);
  }, [open, displayList, selectedIndex, navigate, onOpenChange]);

  const hasRecents = !q && !scope && recents.length > 0;

  /* Flat index mapping for keyboard nav — precomputed, stable across renders */
  const itemIndexMap = useMemo(() => {
    const map = new Map<NavItem, number>();
    let idx = 0;
    grouped.forEach(([, items]) => {
      items.forEach((item) => {
        map.set(item, idx++);
      });
    });
    return map;
  }, [grouped]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl p-0 gap-0 overflow-hidden">
        <DialogTitle className="sr-only">Command Palette</DialogTitle>
        <div className="flex items-center border-b px-4 py-3 gap-3">
          <Search className="h-4 w-4 text-muted-foreground shrink-0" />
          <Input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search pages, modules, actions...  (in:logistics waybill)"
            className="h-8 border-0 shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 px-0 text-base"
          />
          <kbd className="hidden sm:inline-flex h-6 items-center gap-1 rounded border bg-muted px-2 font-mono text-[10px] text-muted-foreground">
            Esc
          </kbd>
        </div>
        <div className="max-h-[60vh] overflow-y-auto py-2">
          {/* ── Live material record hits ── */}
          {(supplyHits.length > 0 || supplyLoading) && (
            <div className="mb-1">
              <div className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                <Package className="h-3 w-3" />
                Materials
                {supplyLoading && <Loader2 className="h-3 w-3 animate-spin ml-1" />}
              </div>
              {supplyHits.map((hit) => (
                <button
                  key={`supply-${hit.id}`}
                  onClick={() =>
                    navigate(`/inventory/supplies?search=${encodeURIComponent(hit.sku)}`)
                  }
                  className="flex w-full items-center gap-3 px-4 py-2.5 text-sm transition-colors text-left hover:bg-muted"
                >
                  <Package className="h-4 w-4 shrink-0 text-primary/80" />
                  <span className="flex-1 min-w-0">
                    <span className="font-medium">{hit.name}</span>
                    <span className="ml-2 font-mono text-xs text-muted-foreground">{hit.sku}</span>
                  </span>
                  <span
                    className={cn(
                      'rounded-full px-2 py-0.5 text-[10px] font-medium',
                      STATUS_CLS[hit.stock_status] ?? 'bg-muted text-muted-foreground'
                    )}
                  >
                    {hit.stock_status?.replace('_', ' ')}
                  </span>
                  <ChevronRight className="h-3 w-3 shrink-0 text-muted-foreground/50" />
                </button>
              ))}
            </div>
          )}

          {grouped.length === 0 && !supplyLoading && supplyHits.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
              <Search className="h-8 w-8 mb-3 opacity-40" />
              <p className="text-sm">No results for "{query}"</p>
              {scope && <p className="text-xs mt-1">Try removing scope: in:{scope}</p>}
            </div>
          ) : (
            grouped.map(([section, items]) => (
              <div key={section}>
                <div className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                  {section === 'Actions' && <Zap className="h-3 w-3" />}
                  {hasRecents && section === 'General' && <Clock className="h-3 w-3" />}
                  {section}
                </div>
                {items.map((item) => {
                  const idx = itemIndexMap.get(item) ?? -1;
                  const Icon = item.icon;
                  const isSelected = idx === selectedIndex;
                  const isAction = item.action;
                  const isRecent = hasRecents && recents.includes(item.href);
                  return (
                    <button
                      key={(item.id ?? item.href) + item.name}
                      onClick={() => navigate(item.href)}
                      onMouseEnter={() => setSelectedIndex(idx)}
                      className={cn(
                        'flex w-full items-center gap-3 px-4 py-2.5 text-sm transition-colors text-left',
                        isSelected
                          ? 'bg-accent text-accent-foreground'
                          : 'text-foreground hover:bg-muted'
                      )}
                    >
                      <Icon
                        className={cn(
                          'h-4 w-4 shrink-0',
                          isAction ? 'text-primary' : 'text-muted-foreground'
                        )}
                      />
                      <span className="flex-1">{item.name}</span>
                      {isAction && (
                        <span className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-primary/10 text-primary">
                          Action
                        </span>
                      )}
                      {isRecent && (
                        <span className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-muted text-muted-foreground">
                          Recent
                        </span>
                      )}
                      <ChevronRight className="h-3 w-3 text-muted-foreground/50" />
                    </button>
                  );
                })}
              </div>
            ))
          )}
        </div>
        <div className="flex items-center gap-4 border-t px-4 py-2 text-[11px] text-muted-foreground">
          <span className="flex items-center gap-1">
            <kbd className="rounded border bg-muted px-1 font-mono">↑↓</kbd> Navigate
          </span>
          <span className="flex items-center gap-1">
            <kbd className="rounded border bg-muted px-1 font-mono">↵</kbd> Select
          </span>
          <span className="flex items-center gap-1">
            <kbd className="rounded border bg-muted px-1 font-mono">Esc</kbd> Close
          </span>
          <span className="ml-auto hidden sm:inline">in:section query</span>
        </div>
      </DialogContent>
    </Dialog>
  );
}
