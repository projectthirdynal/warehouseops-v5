import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
  Search,
  Plus,
  BookUser,
  Users,
  Building2,
  TrendingUp,
  Eye,
  Pencil,
  Trash2,
  MoreHorizontal,
  Phone,
  Mail,
  ShieldAlert,
  ArrowUpDown,
} from 'lucide-react';
import TelesalesLayout from '@/layouts/TelesalesLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import type { PaginatedResponse } from '@/types';

interface ThirdParty {
  id: number;
  ref: string;
  name: string;
  alias: string | null;
  type: string;
  status: string;
  email: string | null;
  phone: string | null;
  city: string | null;
  risk_level: string;
  is_blacklisted: boolean;
  total_orders: number;
  total_revenue: string;
  contacts_count: number;
  created_at: string;
}

interface Props {
  thirdParties: PaginatedResponse<ThirdParty>;
  filters: {
    q?: string;
    type?: string;
    status?: string;
    risk?: string;
    sort?: string;
    dir?: string;
  };
  stats: {
    total: number;
    customers: number;
    suppliers: number;
    prospects: number;
  };
}

const typeConfig: Record<string, { label: string; color: string }> = {
  customer: { label: 'Customer', color: 'bg-info/10 text-info' },
  supplier: { label: 'Supplier', color: 'bg-primary/10 text-primary' },
  prospect: { label: 'Prospect', color: 'bg-warning/10 text-warning' },
  partner: { label: 'Partner', color: 'bg-success/10 text-success' },
  both: { label: 'Cust+Supp', color: 'bg-teal-100 text-teal-800' },
};

const statusConfig: Record<
  string,
  { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
  active: { label: 'Active', variant: 'default' },
  inactive: { label: 'Inactive', variant: 'secondary' },
  prospect: { label: 'Prospect', variant: 'outline' },
  blacklisted: { label: 'Blacklisted', variant: 'destructive' },
};

const riskConfig: Record<string, { label: string; color: string }> = {
  LOW: { label: 'Low', color: 'text-success' },
  MEDIUM: { label: 'Medium', color: 'text-warning' },
  HIGH: { label: 'High', color: 'text-warning' },
  BLACKLISTED: { label: 'Blacklisted', color: 'text-destructive font-semibold' },
};

export default function CrmContactsIndex({ thirdParties, filters, stats }: Props) {
  const [search, setSearch] = useState(filters.q ?? '');

  const applyFilter = (params: Record<string, string | undefined>) => {
    router.get(
      '/crm/contacts',
      { ...filters, ...params, page: undefined },
      {
        preserveState: true,
        replace: true,
      }
    );
  };

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    applyFilter({ q: search || undefined });
  };

  const handleDelete = (id: number, name: string) => {
    if (confirm(`Delete "${name}"? This cannot be undone.`)) {
      router.delete(`/crm/contacts/${id}`);
    }
  };

  const sortBy = (field: string) => {
    const dir = filters.sort === field && filters.dir === 'asc' ? 'desc' : 'asc';
    applyFilter({ sort: field, dir });
  };

  const formatCurrency = (val: string) =>
    `₱${parseFloat(val).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

  return (
    <TelesalesLayout>
      <Head title="CRM — Contacts" />

      <div className="p-6 space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-xl font-bold font-display text-foreground">CRM Contacts</h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              Customers, suppliers, prospects and partners
            </p>
          </div>
          <Link href="/crm/contacts/create">
            <Button>
              <Plus className="h-4 w-4 mr-2" />
              New Contact
            </Button>
          </Link>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {[
            { label: 'Total', value: stats.total, icon: BookUser, color: 'text-muted-foreground' },
            { label: 'Customers', value: stats.customers, icon: Users, color: 'text-info' },
            { label: 'Suppliers', value: stats.suppliers, icon: Building2, color: 'text-primary' },
            { label: 'Prospects', value: stats.prospects, icon: TrendingUp, color: 'text-warning' },
          ].map(({ label, value, icon: Icon, color }) => (
            <Card key={label}>
              <CardContent className="p-4 flex items-center gap-3">
                <Icon className={`h-8 w-8 ${color}`} />
                <div>
                  <p className="text-xl font-bold font-display">{value.toLocaleString()}</p>
                  <p className="text-xs text-muted-foreground">{label}</p>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="p-4">
            <div className="flex flex-wrap gap-3">
              <form onSubmit={handleSearch} className="flex gap-2 flex-1 min-w-[200px]">
                <div className="relative flex-1">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search name, phone, email, ref..."
                    className="pl-9"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                </div>
                <Button type="submit" variant="secondary">
                  Search
                </Button>
              </form>

              <Select
                value={filters.type ?? 'all'}
                onValueChange={(v) => applyFilter({ type: v === 'all' ? undefined : v })}
              >
                <SelectTrigger className="w-36">
                  <SelectValue placeholder="Type" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Types</SelectItem>
                  <SelectItem value="customer">Customer</SelectItem>
                  <SelectItem value="supplier">Supplier</SelectItem>
                  <SelectItem value="prospect">Prospect</SelectItem>
                  <SelectItem value="partner">Partner</SelectItem>
                  <SelectItem value="both">Both</SelectItem>
                </SelectContent>
              </Select>

              <Select
                value={filters.status ?? 'all'}
                onValueChange={(v) => applyFilter({ status: v === 'all' ? undefined : v })}
              >
                <SelectTrigger className="w-36">
                  <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Status</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="inactive">Inactive</SelectItem>
                  <SelectItem value="prospect">Prospect</SelectItem>
                  <SelectItem value="blacklisted">Blacklisted</SelectItem>
                </SelectContent>
              </Select>

              <Select
                value={filters.risk ?? 'all'}
                onValueChange={(v) => applyFilter({ risk: v === 'all' ? undefined : v })}
              >
                <SelectTrigger className="w-36">
                  <SelectValue placeholder="Risk" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Risk</SelectItem>
                  <SelectItem value="LOW">Low</SelectItem>
                  <SelectItem value="MEDIUM">Medium</SelectItem>
                  <SelectItem value="HIGH">High</SelectItem>
                  <SelectItem value="BLACKLISTED">Blacklisted</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </CardContent>
        </Card>

        {/* Table */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">
              {thirdParties.total.toLocaleString()} contacts
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-28">Ref</TableHead>
                  <TableHead>
                    <button
                      onClick={() => sortBy('name')}
                      className="flex items-center gap-1 hover:text-foreground"
                    >
                      Name <ArrowUpDown className="h-3 w-3" />
                    </button>
                  </TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Contact Info</TableHead>
                  <TableHead>City</TableHead>
                  <TableHead>Risk</TableHead>
                  <TableHead className="text-right">
                    <button
                      onClick={() => sortBy('total_orders')}
                      className="flex items-center gap-1 ml-auto hover:text-foreground"
                    >
                      Orders <ArrowUpDown className="h-3 w-3" />
                    </button>
                  </TableHead>
                  <TableHead className="text-right">
                    <button
                      onClick={() => sortBy('total_revenue')}
                      className="flex items-center gap-1 ml-auto hover:text-foreground"
                    >
                      Revenue <ArrowUpDown className="h-3 w-3" />
                    </button>
                  </TableHead>
                  <TableHead className="w-12" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {thirdParties.data.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={10} className="text-center py-12 text-muted-foreground">
                      No contacts found.{' '}
                      <Link href="/crm/contacts/create" className="underline">
                        Create the first one.
                      </Link>
                    </TableCell>
                  </TableRow>
                ) : (
                  thirdParties.data.map((tp) => (
                    <TableRow key={tp.id} className="hover:bg-muted/50">
                      <TableCell className="font-mono text-xs text-muted-foreground">
                        {tp.ref ?? '—'}
                      </TableCell>
                      <TableCell>
                        <Link
                          href={`/crm/contacts/${tp.id}`}
                          className="font-medium hover:underline"
                        >
                          {tp.name}
                        </Link>
                        {tp.alias && <p className="text-xs text-muted-foreground">{tp.alias}</p>}
                      </TableCell>
                      <TableCell>
                        <span
                          className={`text-xs px-2 py-0.5 rounded-full font-medium ${typeConfig[tp.type]?.color ?? 'bg-muted text-muted-foreground'}`}
                        >
                          {typeConfig[tp.type]?.label ?? tp.type}
                        </span>
                      </TableCell>
                      <TableCell>
                        <Badge variant={statusConfig[tp.status]?.variant ?? 'outline'}>
                          {statusConfig[tp.status]?.label ?? tp.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        <div className="space-y-0.5">
                          {tp.phone && (
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                              <Phone className="h-3 w-3" />
                              {tp.phone}
                            </div>
                          )}
                          {tp.email && (
                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                              <Mail className="h-3 w-3" />
                              {tp.email}
                            </div>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-sm">{tp.city ?? '—'}</TableCell>
                      <TableCell>
                        {tp.is_blacklisted ? (
                          <span className="flex items-center gap-1 text-xs text-destructive font-semibold">
                            <ShieldAlert className="h-3 w-3" /> Blacklisted
                          </span>
                        ) : (
                          <span
                            className={`text-xs ${riskConfig[tp.risk_level]?.color ?? 'text-muted-foreground'}`}
                          >
                            {riskConfig[tp.risk_level]?.label ?? tp.risk_level}
                          </span>
                        )}
                      </TableCell>
                      <TableCell className="text-right text-sm">
                        {tp.total_orders.toLocaleString()}
                      </TableCell>
                      <TableCell className="text-right text-sm font-medium">
                        {formatCurrency(tp.total_revenue)}
                      </TableCell>
                      <TableCell>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="h-8 w-8">
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                              <Link href={`/crm/contacts/${tp.id}`}>
                                <Eye className="h-4 w-4 mr-2" /> View
                              </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                              <Link href={`/crm/contacts/${tp.id}/edit`}>
                                <Pencil className="h-4 w-4 mr-2" /> Edit
                              </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              className="text-destructive"
                              onClick={() => handleDelete(tp.id, tp.name)}
                            >
                              <Trash2 className="h-4 w-4 mr-2" /> Delete
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>

        {/* Pagination */}
        {thirdParties.last_page > 1 && (
          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Showing {thirdParties.from}–{thirdParties.to} of {thirdParties.total}
            </span>
            <div className="flex gap-2">
              {thirdParties.links.map((link, i) => (
                <Button
                  key={i}
                  variant={link.active ? 'default' : 'outline'}
                  size="sm"
                  disabled={!link.url}
                  onClick={() => link.url && router.get(link.url)}
                  dangerouslySetInnerHTML={{ __html: link.label }}
                />
              ))}
            </div>
          </div>
        )}
      </div>
    </TelesalesLayout>
  );
}
