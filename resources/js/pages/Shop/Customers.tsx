import { FormEvent, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, MessageSquare, Search, ShieldAlert, ShoppingCart, UserRound, Users } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

interface Customer {
  id: number;
  name: string;
  phone: string;
  normalized_phone?: string | null;
  facebook_name?: string | null;
  canonical_address?: string | null;
  barangay?: string | null;
  city_municipality?: string | null;
  province?: string | null;
  last_order_date?: string | null;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  success_rate: string | number;
  total_revenue: string | number;
  risk_level: string;
  is_blacklisted: boolean;
  blacklist_reason?: string | null;
  shop_orders_count: number;
  shop_revenue_total: string | number;
  conversations_count: number;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
}

interface Props {
  customers: Paginated<Customer>;
  filters: {
    q?: string;
    risk_level?: string;
  };
  risk_levels: string[];
  summary: {
    customers: number;
    with_shop_orders: number;
    high_risk: number;
    blacklisted: number;
  };
}

function money(value: string | number) {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value ?? 0));
}

function label(value: string) {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function riskClass(customer: Customer) {
  if (customer.is_blacklisted || customer.risk_level === 'BLACKLISTED') return 'bg-red-100 text-red-800 border-red-200';
  if (customer.risk_level === 'HIGH') return 'bg-amber-100 text-amber-800 border-amber-200';
  if (customer.risk_level === 'MEDIUM') return 'bg-blue-100 text-blue-800 border-blue-200';
  return 'bg-emerald-100 text-emerald-800 border-emerald-200';
}

function address(customer: Customer) {
  return customer.canonical_address
    || [customer.barangay, customer.city_municipality, customer.province].filter(Boolean).join(', ')
    || 'No saved address';
}

export default function ShopCustomers({ customers, filters, risk_levels, summary }: Props) {
  const [search, setSearch] = useState(filters.q ?? '');

  const updateFilters = (next: Record<string, string | undefined>) => {
    router.get('/shop/customers', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
  };

  const submitSearch = (event: FormEvent) => {
    event.preventDefault();
    updateFilters({ q: search || undefined });
  };

  const summaryCards = [
    { label: 'Customers', value: summary.customers.toLocaleString(), icon: Users },
    { label: 'With Shop Orders', value: summary.with_shop_orders.toLocaleString(), icon: ShoppingCart },
    { label: 'High Risk', value: summary.high_risk.toLocaleString(), icon: ShieldAlert },
    { label: 'Blacklisted', value: summary.blacklisted.toLocaleString(), icon: AlertTriangle },
  ];

  return (
    <AppLayout>
      <Head title="Shop Customers" />

      <div className="space-y-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
          <div>
            <h1 className="text-3xl font-bold tracking-tight">Shop Customers</h1>
            <p className="text-muted-foreground">CRM customer book for repeat buyers, address context, and risk checks.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button asChild>
              <Link href="/shop/pos">
                <ShoppingCart className="mr-2 h-4 w-4" />
                POS Register
              </Link>
            </Button>
            <Button asChild variant="outline">
              <Link href="/shop/inbox">
                <MessageSquare className="mr-2 h-4 w-4" />
                CRM Inbox
              </Link>
            </Button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {summaryCards.map((item) => {
            const Icon = item.icon;
            return (
              <Card key={item.label}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">{item.label}</CardTitle>
                  <Icon className="h-4 w-4 text-muted-foreground" />
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{item.value}</div>
                </CardContent>
              </Card>
            );
          })}
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Customer Book</CardTitle>
            <CardDescription>Search by name, Facebook name, or phone number.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-col gap-3 md:flex-row">
              <form onSubmit={submitSearch} className="flex min-w-0 flex-1 gap-2">
                <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search customers..." />
                <Button type="submit" variant="outline">
                  <Search className="h-4 w-4" />
                </Button>
              </form>
              <select
                value={filters.risk_level ?? ''}
                onChange={(event) => updateFilters({ risk_level: event.target.value || undefined })}
                className="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="">All risk levels</option>
                {risk_levels.map((risk) => (
                  <option key={risk} value={risk}>{label(risk)}</option>
                ))}
              </select>
            </div>

            {customers.data.length === 0 ? (
              <div className="rounded-lg border py-14 text-center text-muted-foreground">
                <UserRound className="mx-auto mb-3 h-10 w-10 opacity-30" />
                <p className="font-medium">No customers found</p>
                <p className="text-sm">Customers are created when POS or CRM orders are recorded.</p>
              </div>
            ) : (
              <div className="grid gap-3">
                {customers.data.map((customer) => (
                  <div key={customer.id} className="rounded-lg border p-4">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <h3 className="font-semibold">{customer.name}</h3>
                          <Badge variant="outline" className={riskClass(customer)}>{label(customer.risk_level)}</Badge>
                          {customer.facebook_name && <Badge variant="secondary">{customer.facebook_name}</Badge>}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">{customer.normalized_phone ?? customer.phone}</p>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{address(customer)}</p>
                        {customer.is_blacklisted && customer.blacklist_reason && (
                          <p className="mt-2 text-sm text-red-700">{customer.blacklist_reason}</p>
                        )}
                      </div>
                      <div className="grid min-w-[320px] gap-3 text-sm sm:grid-cols-4 lg:text-right">
                        <div>
                          <p className="text-muted-foreground">Shop Orders</p>
                          <p className="font-semibold">{Number(customer.shop_orders_count ?? customer.total_orders).toLocaleString()}</p>
                        </div>
                        <div>
                          <p className="text-muted-foreground">Revenue</p>
                          <p className="font-semibold">{money(customer.shop_revenue_total ?? customer.total_revenue)}</p>
                        </div>
                        <div>
                          <p className="text-muted-foreground">CRM Threads</p>
                          <p className="font-semibold">{Number(customer.conversations_count ?? 0).toLocaleString()}</p>
                        </div>
                        <div>
                          <p className="text-muted-foreground">Success</p>
                          <p className="font-semibold">{Number(customer.success_rate ?? 0)}%</p>
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {customers.last_page > 1 && (
          <div className="flex justify-center gap-2">
            {Array.from({ length: customers.last_page }, (_, index) => index + 1).map((page) => (
              <Button
                key={page}
                size="sm"
                variant={page === customers.current_page ? 'default' : 'outline'}
                onClick={() => router.get('/shop/customers', { ...filters, page }, { preserveScroll: true })}
              >
                {page}
              </Button>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
