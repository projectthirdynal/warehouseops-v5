import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import type { PaginatedResponse } from '@/types';

interface Customer {
  id: number;
  name: string;
  phone: string;
  normalized_phone: string;
  facebook_name: string | null;
  canonical_address: string | null;
  risk_level: string;
  is_blacklisted: boolean;
  total_orders: number;
  last_order_date: string | null;
}

interface Props {
  customers: PaginatedResponse<Customer>;
  filters: { q?: string };
}

export default function CustomersIndex({ customers, filters }: Props) {
  const [q, setQ] = useState(filters.q ?? '');

  const handleSearch = () => {
    router.get('/shop/customers', { q }, { preserveState: true, replace: true });
  };

  return (
    <AppLayout>
      <Head title="Customers" />
      <div className="space-y-4 p-4">
        <div className="flex items-center justify-between">
          <h1 className="text-xl font-bold">Customers</h1>
        </div>
        <div className="flex gap-2">
          <Input
            placeholder="Search by name, phone, or Facebook name"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          />
          <Button onClick={handleSearch}>Search</Button>
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Phone</TableHead>
              <TableHead>Facebook</TableHead>
              <TableHead>Address</TableHead>
              <TableHead>Risk</TableHead>
              <TableHead>Orders</TableHead>
              <TableHead>Last Order</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {customers.data.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center text-muted-foreground">
                  No customers found.
                </TableCell>
              </TableRow>
            ) : (
              customers.data.map((customer) => (
                <TableRow key={customer.id}>
                  <TableCell>{customer.name}</TableCell>
                  <TableCell>{customer.phone}</TableCell>
                  <TableCell>{customer.facebook_name ?? '-'}</TableCell>
                  <TableCell>{customer.canonical_address ?? '-'}</TableCell>
                  <TableCell>
                    <Badge variant={customer.is_blacklisted ? 'destructive' : 'secondary'}>
                      {customer.is_blacklisted ? 'BLACKLISTED' : customer.risk_level}
                    </Badge>
                  </TableCell>
                  <TableCell>{customer.total_orders}</TableCell>
                  <TableCell>
                    {customer.last_order_date
                      ? new Date(customer.last_order_date).toLocaleDateString()
                      : '-'}
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
        {customers.last_page > 1 && (
          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Showing {customers.from}–{customers.to} of {customers.total}
            </span>
            <div className="flex gap-2">
              {customers.links.map((link, i) => (
                <Button
                  key={i}
                  variant={link.active ? 'default' : 'outline'}
                  size="sm"
                  asChild
                  disabled={!link.url}
                >
                  <Link href={link.url ?? '#'} dangerouslySetInnerHTML={{ __html: link.label }} />
                </Button>
              ))}
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
