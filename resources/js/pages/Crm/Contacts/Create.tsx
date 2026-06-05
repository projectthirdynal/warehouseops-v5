import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';

export default function CrmContactsCreate() {
  const { data, setData, post, processing, errors } = useForm({
    // Core
    name: '',
    alias: '',
    type: 'customer',
    status: 'active',
    // Contact info
    email: '',
    phone: '',
    phone_alt: '',
    website: '',
    // Business
    tax_id: '',
    industry: '',
    currency: 'PHP',
    payment_terms: '',
    credit_limit: '',
    // Primary address
    address_line1: '',
    city: '',
    state_province: '',
    postal_code: '',
    country: 'Philippines',
    // Notes
    notes: '',
    // Optional primary contact person
    'contact.first_name': '',
    'contact.last_name': '',
    'contact.email': '',
    'contact.phone': '',
    'contact.position': '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/crm/contacts');
  };

  return (
    <AppLayout>
      <Head title="New Contact — CRM" />

      <div className="p-6 max-w-3xl space-y-6">
        <div className="flex items-center gap-3">
          <Link href="/crm/contacts">
            <Button variant="ghost" size="icon"><ArrowLeft className="h-4 w-4" /></Button>
          </Link>
          <div>
            <h1 className="text-2xl font-bold">New Contact</h1>
            <p className="text-sm text-muted-foreground">Add a customer, supplier, or prospect</p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* Basic Info */}
          <Card>
            <CardHeader><CardTitle className="text-base">Basic Information</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2 md:col-span-1">
                  <Label>Name *</Label>
                  <Input
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="Company or person name"
                    required
                  />
                  {errors.name && <p className="text-xs text-red-600 mt-1">{errors.name}</p>}
                </div>
                <div>
                  <Label>Alias / Short Name</Label>
                  <Input
                    value={data.alias}
                    onChange={(e) => setData('alias', e.target.value)}
                    placeholder="Optional alias"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Type *</Label>
                  <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="customer">Customer</SelectItem>
                      <SelectItem value="supplier">Supplier</SelectItem>
                      <SelectItem value="prospect">Prospect</SelectItem>
                      <SelectItem value="partner">Partner</SelectItem>
                      <SelectItem value="both">Customer + Supplier</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Status</Label>
                  <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="active">Active</SelectItem>
                      <SelectItem value="prospect">Prospect</SelectItem>
                      <SelectItem value="inactive">Inactive</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Contact Info */}
          <Card>
            <CardHeader><CardTitle className="text-base">Contact Information</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Phone</Label>
                  <Input value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="+63..." />
                </div>
                <div>
                  <Label>Alternate Phone</Label>
                  <Input value={data.phone_alt} onChange={(e) => setData('phone_alt', e.target.value)} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Email</Label>
                  <Input type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                  {errors.email && <p className="text-xs text-red-600 mt-1">{errors.email}</p>}
                </div>
                <div>
                  <Label>Website</Label>
                  <Input type="url" value={data.website} onChange={(e) => setData('website', e.target.value)} placeholder="https://..." />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Address */}
          <Card>
            <CardHeader><CardTitle className="text-base">Primary Address</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label>Address Line 1</Label>
                <Input value={data.address_line1} onChange={(e) => setData('address_line1', e.target.value)} />
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>City</Label>
                  <Input value={data.city} onChange={(e) => setData('city', e.target.value)} />
                </div>
                <div>
                  <Label>Province</Label>
                  <Input value={data.state_province} onChange={(e) => setData('state_province', e.target.value)} />
                </div>
                <div>
                  <Label>Postal Code</Label>
                  <Input value={data.postal_code} onChange={(e) => setData('postal_code', e.target.value)} />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Business Details */}
          <Card>
            <CardHeader><CardTitle className="text-base">Business Details</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>TIN / Tax ID</Label>
                  <Input value={data.tax_id} onChange={(e) => setData('tax_id', e.target.value)} />
                </div>
                <div>
                  <Label>Industry</Label>
                  <Input value={data.industry} onChange={(e) => setData('industry', e.target.value)} />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Currency</Label>
                  <Select value={data.currency} onValueChange={(v) => setData('currency', v)}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="PHP">PHP — Philippine Peso</SelectItem>
                      <SelectItem value="USD">USD — US Dollar</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Payment Terms</Label>
                  <Select value={data.payment_terms} onValueChange={(v) => setData('payment_terms', v)}>
                    <SelectTrigger><SelectValue placeholder="Select..." /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="COD">COD</SelectItem>
                      <SelectItem value="IMMEDIATE">Immediate</SelectItem>
                      <SelectItem value="NET15">NET 15</SelectItem>
                      <SelectItem value="NET30">NET 30</SelectItem>
                      <SelectItem value="NET60">NET 60</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label>Credit Limit (₱)</Label>
                  <Input type="number" min="0" value={data.credit_limit} onChange={(e) => setData('credit_limit', e.target.value)} placeholder="0.00" />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Primary Contact Person */}
          <Card>
            <CardHeader><CardTitle className="text-base">Primary Contact Person <span className="text-muted-foreground font-normal text-sm">(optional)</span></CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>First Name</Label>
                  <Input value={data['contact.first_name']} onChange={(e) => setData('contact.first_name', e.target.value)} />
                </div>
                <div>
                  <Label>Last Name</Label>
                  <Input value={data['contact.last_name']} onChange={(e) => setData('contact.last_name', e.target.value)} />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Position</Label>
                  <Input value={data['contact.position']} onChange={(e) => setData('contact.position', e.target.value)} />
                </div>
                <div>
                  <Label>Email</Label>
                  <Input type="email" value={data['contact.email']} onChange={(e) => setData('contact.email', e.target.value)} />
                </div>
                <div>
                  <Label>Phone</Label>
                  <Input value={data['contact.phone']} onChange={(e) => setData('contact.phone', e.target.value)} />
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Notes */}
          <Card>
            <CardHeader><CardTitle className="text-base">Notes</CardTitle></CardHeader>
            <CardContent>
              <Textarea
                value={data.notes}
                onChange={(e) => setData('notes', e.target.value)}
                placeholder="Internal notes about this contact..."
                rows={3}
              />
            </CardContent>
          </Card>

          {/* Actions */}
          <div className="flex gap-3 justify-end">
            <Link href="/crm/contacts">
              <Button type="button" variant="outline">Cancel</Button>
            </Link>
            <Button type="submit" disabled={processing}>
              {processing ? 'Saving...' : 'Create Contact'}
            </Button>
          </div>
        </form>
      </div>
    </AppLayout>
  );
}
