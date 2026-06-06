import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { router } from '@inertiajs/react';
import {
  Dialog,
  DialogContent,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard, Package, Users, Truck, ClipboardCheck,
  BarChart3, Settings, Phone, Recycle, UserCog, MessageSquare,
  Shield, FileText, ShoppingCart, PackageCheck, Building2,
  TrendingUp, Store, BookUser, ScanLine, Upload, ShieldAlert,
  AlertOctagon, HelpCircle, ChevronRight, Search,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

interface NavItem {
  name: string;
  href: string;
  icon: LucideIcon;
  section: string;
  keywords?: string[];
}

const allNavItems: NavItem[] = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard, section: 'General' },
  { name: 'Shop', href: '/shop', icon: Store, section: 'General' },
  { name: 'All Waybills', href: '/waybills', icon: Truck, section: 'Logistics', keywords: ['waybill', 'shipping'] },
  { name: 'Scanner', href: '/waybills/scanner', icon: ScanLine, section: 'Logistics' },
  { name: 'Import', href: '/waybills/import', icon: Upload, section: 'Logistics' },
  { name: 'Claims', href: '/waybills/claims', icon: ShieldAlert, section: 'Logistics' },
  { name: 'Beyond SLA', href: '/waybills/claims/beyond-sla', icon: AlertOctagon, section: 'Logistics' },
  { name: 'Unknown', href: '/waybills/unknown', icon: HelpCircle, section: 'Logistics' },
  { name: 'My Leads', href: '/agent/leads', icon: Phone, section: 'Logistics' },
  { name: 'Leads', href: '/leads', icon: Users, section: 'Logistics' },
  { name: 'All Contacts', href: '/crm/contacts', icon: BookUser, section: 'CRM' },
  { name: 'Customers', href: '/crm/contacts?type=customer', icon: Users, section: 'CRM' },
  { name: 'Suppliers', href: '/crm/contacts?type=supplier', icon: Building2, section: 'CRM' },
  { name: 'Prospects', href: '/crm/contacts?type=prospect', icon: TrendingUp, section: 'CRM' },
  { name: 'QC Review', href: '/qc', icon: ClipboardCheck, section: 'Operations' },
  { name: 'Recycling', href: '/recycling/pool', icon: Recycle, section: 'System' },
  { name: 'Monitoring', href: '/monitoring/dashboard', icon: BarChart3, section: 'System' },
  { name: 'Sales', href: '/sales', icon: TrendingUp, section: 'Commercial' },
  { name: 'Agents', href: '/agents/governance', icon: UserCog, section: 'System' },
  { name: 'SMS', href: '/sms', icon: MessageSquare, section: 'System' },
  { name: 'Orders', href: '/orders', icon: ClipboardCheck, section: 'Operations' },
  { name: 'Reports', href: '/reports', icon: ClipboardCheck, section: 'System' },
  { name: 'Admin', href: '/admin', icon: Shield, section: 'System' },
  { name: 'Inventory Dashboard', href: '/inventory', icon: BarChart3, section: 'Operations' },
  { name: 'Movements', href: '/inventory/movements', icon: Recycle, section: 'Operations' },
  { name: 'Stock Adjustments', href: '/inventory/adjustments', icon: ClipboardCheck, section: 'Operations' },
  { name: 'Supplies', href: '/inventory/supplies', icon: Package, section: 'Operations' },
  { name: 'Products', href: '/products', icon: Package, section: 'Operations' },
  { name: 'Warehouses', href: '/warehouses', icon: Building2, section: 'Operations' },
  { name: 'Procurement Suppliers', href: '/procurement/suppliers', icon: Building2, section: 'Procurement' },
  { name: 'Purchase Requests', href: '/procurement/requests', icon: FileText, section: 'Procurement' },
  { name: 'Purchase Orders', href: '/procurement/orders', icon: ShoppingCart, section: 'Procurement' },
  { name: 'Receiving (GR)', href: '/procurement/receiving', icon: PackageCheck, section: 'Procurement' },
  { name: 'Finance Overview', href: '/finance', icon: BarChart3, section: 'Commercial' },
  { name: 'QuickBooks', href: '/finance/quickbooks', icon: Building2, section: 'Commercial' },
  { name: 'Cost of Goods', href: '/finance/cost-of-goods', icon: Package, section: 'Commercial' },
  { name: 'Invoices', href: '/finance/invoices', icon: FileText, section: 'Commercial' },
  { name: 'Supplier Invoices', href: '/finance/supplier-invoices', icon: Building2, section: 'Commercial' },
  { name: 'Couriers', href: '/couriers', icon: Truck, section: 'Logistics' },
  { name: 'Tickets', href: '/tickets', icon: Phone, section: 'Commercial' },
  { name: 'Settings', href: '/settings', icon: Settings, section: 'System' },
];

interface CommandPaletteProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export default function CommandPalette({ open, onOpenChange }: CommandPaletteProps) {
  const [query, setQuery] = useState('');
  const [selectedIndex, setSelectedIndex] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);

  const filtered = useMemo(() => {
    if (!query.trim()) return allNavItems;
    const q = query.toLowerCase();
    return allNavItems.filter((item) => {
      const text = `${item.name} ${item.section} ${(item.keywords ?? []).join(' ')}`.toLowerCase();
      return text.includes(q);
    });
  }, [query]);

  const grouped = useMemo(() => {
    const map = new Map<string, NavItem[]>();
    filtered.forEach((item) => {
      const list = map.get(item.section) ?? [];
      list.push(item);
      map.set(item.section, list);
    });
    return Array.from(map.entries());
  }, [filtered]);

  const flatItems = useMemo(() => filtered, [filtered]);

  const navigate = useCallback((href: string) => {
    onOpenChange(false);
    setQuery('');
    setSelectedIndex(0);
    router.visit(href);
  }, [onOpenChange]);

  useEffect(() => {
    setSelectedIndex(0);
  }, [query]);

  useEffect(() => {
    if (open && inputRef.current) {
      setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [open]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (!open) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setSelectedIndex((i) => (i + 1) % flatItems.length);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setSelectedIndex((i) => (i - 1 + flatItems.length) % flatItems.length);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        const item = flatItems[selectedIndex];
        if (item) navigate(item.href);
      } else if (e.key === 'Escape') {
        onOpenChange(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [open, flatItems, selectedIndex, navigate, onOpenChange]);

  let globalIdx = 0;

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
            placeholder="Search pages, modules, actions..."
            className="h-8 border-0 shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 px-0 text-base"
          />
          <kbd className="hidden sm:inline-flex h-6 items-center gap-1 rounded border bg-muted px-2 font-mono text-[10px] text-muted-foreground">
            <span className="text-xs">Esc</span>
          </kbd>
        </div>
        <div className="max-h-[60vh] overflow-y-auto py-2">
          {grouped.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
              <Search className="h-8 w-8 mb-3 opacity-40" />
              <p className="text-sm">No results found for "{query}"</p>
            </div>
          ) : (
            grouped.map(([section, items]) => (
              <div key={section}>
                <div className="px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  {section}
                </div>
                {items.map((item) => {
                  const idx = globalIdx++;
                  const Icon = item.icon;
                  const isSelected = idx === selectedIndex;
                  return (
                    <button
                      key={item.href + item.name}
                      onClick={() => navigate(item.href)}
                      onMouseEnter={() => setSelectedIndex(idx)}
                      className={cn(
                        'flex w-full items-center gap-3 px-4 py-2.5 text-sm transition-colors text-left',
                        isSelected
                          ? 'bg-accent text-accent-foreground'
                          : 'text-foreground hover:bg-muted'
                      )}
                    >
                      <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="flex-1">{item.name}</span>
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
        </div>
      </DialogContent>
    </Dialog>
  );
}
