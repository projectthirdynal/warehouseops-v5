import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

interface ThirdParty {
  id: number;
  ref: string;
  name: string;
  alias: string | null;
  type: string;
  status: string;
  email: string | null;
  phone: string | null;
  phone_alt: string | null;
  website: string | null;
  tax_id: string | null;
  industry: string | null;
  currency: string;
  payment_terms: string | null;
  credit_limit: string;
  address_line1: string | null;
  city: string | null;
  state_province: string | null;
  postal_code: string | null;
  country: string;
  notes: string | null;
  tags: string[] | null;
}

interface Props {
  thirdParty: ThirdParty;
}

export default function CrmContactsEdit({ thirdParty }: Props) {
  const { data, setData, patch, processing, errors } = useForm({
    name: thirdParty.name,
    alias: thirdParty.alias ?? '',
    type: thirdParty.type,
    email: thirdParty.email ?? '',
    phone: thirdParty.phone ?? '',
    phone_alt: thirdParty.phone_alt ?? '',
    website: thirdParty.website ?? '',
    tax_id: thirdParty.tax_id ?? '',
    industry: thirdParty.industry ?? '',
    currency: thirdParty.currency ?? 'PHP',
    payment_terms: thirdParty.payment_terms ?? '',
    credit_limit: thirdParty.credit_limit ?? '',
    address_line1: thirdParty.address_line1 ?? '',
    city: thirdParty.city ?? '',
    state_province: thirdParty.state_province ?? '',
    postal_code: thirdParty.postal_code ?? '',
    country: thirdParty.country ?? 'Philippines',
    status: thirdParty.status,
    notes: thirdParty.notes ?? '',
    tags: thirdParty.tags ?? [],
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    patch(`/crm/contacts/${thirdParty.id}`, {
      preserveScroll: true,
    });
  };

  return (
    <AppLayout>
      <Head title={`Edit ${thirdParty.name} — CRM`} />
      <div className="p-6 max-w-3xl mx-auto space-y-6">
        <div className="flex items-center gap-3">
          <Link href={`/crm/contacts/${thirdParty.id}`}>
            <Button variant="ghost" size="icon">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-xl font-bold font-display">Edit {thirdParty.name}</h1>
            <p className="text-sm text-muted-foreground">{thirdParty.ref}</p>
          </div>
        </div>

        <form onSubmit={submit} className="space-y-6">
          {/* Basic Info */}
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Basic Information</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <Label>Name *</Label>
                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                {errors.name && <p className="text-xs text-destructive mt-1">{errors.name}</p>}
              </div>
              <div>
                <Label>Alias</Label>
                <Input value={data.alias} onChange={(e) => setData('alias', e.target.value)} />
              </div>
              <div>
                <Label>Type *</Label>
                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="customer">Customer</SelectItem>
                    <SelectItem value="supplier">Supplier</SelectItem>
                    <SelectItem value="prospect">Prospect</SelectItem>
                    <SelectItem value="partner">Partner</SelectItem>
                    <SelectItem value="both">Customer + Supplier</SelectItem>
                  </SelectContent>
                </Select>
                {errors.type && <p className="text-xs text-destructive mt-1">{errors.type}</p>}
              </div>
              <div>
                <Label>Status</Label>
                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                    <SelectItem value="prospect">Prospect</SelectItem>
                    <SelectItem value="blacklisted">Blacklisted</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </CardContent>
          </Card>

          {/* Contact Info */}
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Contact Information</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <Label>Email</Label>
                <Input
                  type="email"
                  value={data.email}
                  onChange={(e) => setData('email', e.target.value)}
                />
                {errors.email && <p className="text-xs text-destructive mt-1">{errors.email}</p>}
              </div>
              <div>
                <Label>Phone</Label>
                <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
              </div>
              <div>
                <Label>Phone (Alt)</Label>
                <Input
                  value={data.phone_alt}
                  onChange={(e) => setData('phone_alt', e.target.value)}
                />
              </div>
              <div>
                <Label>Website</Label>
                <Input
                  value={data.website}
                  onChange={(e) => setData('website', e.target.value)}
                  placeholder="https://"
                />
                {errors.website && (
                  <p className="text-xs text-destructive mt-1">{errors.website}</p>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Business Info */}
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Business Details</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <Label>Tax ID</Label>
                <Input value={data.tax_id} onChange={(e) => setData('tax_id', e.target.value)} />
              </div>
              <div>
                <Label>Industry</Label>
                <Input
                  value={data.industry}
                  onChange={(e) => setData('industry', e.target.value)}
                />
              </div>
              <div>
                <Label>Currency</Label>
                <Input
                  value={data.currency}
                  onChange={(e) => setData('currency', e.target.value)}
                  maxLength={3}
                />
              </div>
              <div>
                <Label>Payment Terms</Label>
                <Input
                  value={data.payment_terms}
                  onChange={(e) => setData('payment_terms', e.target.value)}
                  placeholder="e.g. NET_30"
                />
              </div>
              <div>
                <Label>Credit Limit</Label>
                <Input
                  type="number"
                  step="0.01"
                  value={data.credit_limit}
                  onChange={(e) => setData('credit_limit', e.target.value)}
                />
                {errors.credit_limit && (
                  <p className="text-xs text-destructive mt-1">{errors.credit_limit}</p>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Address */}
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Address</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="md:col-span-2">
                <Label>Address Line 1</Label>
                <Input
                  value={data.address_line1}
                  onChange={(e) => setData('address_line1', e.target.value)}
                />
              </div>
              <div>
                <Label>City</Label>
                <Input value={data.city} onChange={(e) => setData('city', e.target.value)} />
              </div>
              <div>
                <Label>State / Province</Label>
                <Input
                  value={data.state_province}
                  onChange={(e) => setData('state_province', e.target.value)}
                />
              </div>
              <div>
                <Label>Postal Code</Label>
                <Input
                  value={data.postal_code}
                  onChange={(e) => setData('postal_code', e.target.value)}
                />
              </div>
              <div>
                <Label>Country</Label>
                <Input value={data.country} onChange={(e) => setData('country', e.target.value)} />
              </div>
            </CardContent>
          </Card>

          {/* Notes */}
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Notes</CardTitle>
            </CardHeader>
            <CardContent>
              <Textarea
                value={data.notes}
                onChange={(e) => setData('notes', e.target.value)}
                rows={4}
              />
            </CardContent>
          </Card>

          {/* Actions */}
          <div className="flex justify-end gap-2">
            <Link href={`/crm/contacts/${thirdParty.id}`}>
              <Button type="button" variant="outline">
                Cancel
              </Button>
            </Link>
            <Button type="submit" disabled={processing}>
              Save Changes
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
