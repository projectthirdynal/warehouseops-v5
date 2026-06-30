import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
  ArrowLeft,
  Pencil,
  Plus,
  MapPin,
  Phone,
  Mail,
  Globe,
  Building2,
  ShieldAlert,
  Users,
  Package,
  CreditCard,
  FileText,
  Star,
} from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useForm } from '@inertiajs/react';

interface Contact {
  id: number;
  first_name: string;
  last_name: string | null;
  title: string | null;
  position: string | null;
  department: string | null;
  email: string | null;
  phone: string | null;
  is_primary: boolean;
}

interface Address {
  id: number;
  type: string;
  label: string | null;
  is_default: boolean;
  address_line1: string;
  address_line2: string | null;
  barangay: string | null;
  city: string;
  state_province: string | null;
  postal_code: string | null;
  country: string;
  contact_name: string | null;
  contact_phone: string | null;
}

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
  country: string;
  notes: string | null;
  tags: string[] | null;
  risk_level: string;
  is_blacklisted: boolean;
  blacklist_reason: string | null;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  total_revenue: string;
  success_rate: string;
  last_order_date: string | null;
  contacts: Contact[];
  addresses: Address[];
  created_at: string;
}

interface Order {
  id: number;
  order_number: string;
  status: string;
  total_amount: string;
  created_at: string;
}

interface Props {
  thirdParty: ThirdParty;
  recentOrders: Order[];
}

const typeLabels: Record<string, string> = {
  customer: 'Customer',
  supplier: 'Supplier',
  prospect: 'Prospect',
  partner: 'Partner',
  both: 'Customer + Supplier',
};

const statusVariant: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  active: 'default',
  inactive: 'secondary',
  prospect: 'outline',
  blacklisted: 'destructive',
};

export default function CrmContactsShow({ thirdParty, recentOrders }: Props) {
  const [showAddContact, setShowAddContact] = useState(false);
  const [showAddAddress, setShowAddAddress] = useState(false);

  const contactForm = useForm({
    first_name: '',
    last_name: '',
    title: '',
    position: '',
    department: '',
    email: '',
    phone: '',
    is_primary: false,
    notes: '',
  });

  const addressForm = useForm({
    type: 'billing',
    label: '',
    is_default: false,
    address_line1: '',
    address_line2: '',
    barangay: '',
    city: '',
    state_province: '',
    postal_code: '',
    country: 'Philippines',
    contact_name: '',
    contact_phone: '',
  });

  const submitContact = (e: React.FormEvent) => {
    e.preventDefault();
    contactForm.post(`/crm/contacts/${thirdParty.id}/contacts`, {
      onSuccess: () => {
        setShowAddContact(false);
        contactForm.reset();
      },
    });
  };

  const submitAddress = (e: React.FormEvent) => {
    e.preventDefault();
    addressForm.post(`/crm/contacts/${thirdParty.id}/addresses`, {
      onSuccess: () => {
        setShowAddAddress(false);
        addressForm.reset();
      },
    });
  };

  const formatCurrency = (val: string) =>
    `₱${parseFloat(val).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;

  return (
    <AppLayout>
      <Head title={`${thirdParty.name} — CRM`} />

      <div className="p-6 space-y-6">
        {/* Header */}
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <Link href="/crm/contacts">
              <Button variant="ghost" size="icon">
                <ArrowLeft className="h-4 w-4" />
              </Button>
            </Link>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-bold font-display">{thirdParty.name}</h1>
                {thirdParty.alias && (
                  <span className="text-muted-foreground text-sm">({thirdParty.alias})</span>
                )}
                <Badge variant={statusVariant[thirdParty.status] ?? 'outline'}>
                  {thirdParty.status}
                </Badge>
                {thirdParty.is_blacklisted && (
                  <Badge variant="destructive">
                    <ShieldAlert className="h-3 w-3 mr-1" /> Blacklisted
                  </Badge>
                )}
              </div>
              <p className="text-sm text-muted-foreground mt-0.5">
                {thirdParty.ref} · {typeLabels[thirdParty.type] ?? thirdParty.type}
              </p>
            </div>
          </div>
          <Link href={`/crm/contacts/${thirdParty.id}/edit`}>
            <Button variant="outline">
              <Pencil className="h-4 w-4 mr-2" /> Edit
            </Button>
          </Link>
        </div>

        {/* Stats Row */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {[
            {
              label: 'Total Orders',
              value: thirdParty.total_orders.toLocaleString(),
              icon: Package,
            },
            {
              label: 'Successful',
              value: thirdParty.successful_orders.toLocaleString(),
              icon: Package,
            },
            {
              label: 'Total Revenue',
              value: formatCurrency(thirdParty.total_revenue),
              icon: CreditCard,
            },
            { label: 'Success Rate', value: `${thirdParty.success_rate}%`, icon: Star },
          ].map(({ label, value, icon: Icon }) => (
            <Card key={label}>
              <CardContent className="p-4 flex items-center gap-3">
                <Icon className="h-6 w-6 text-muted-foreground" />
                <div>
                  <p className="text-lg font-bold">{value}</p>
                  <p className="text-xs text-muted-foreground">{label}</p>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="grid md:grid-cols-3 gap-6">
          {/* Left: Info card */}
          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              {thirdParty.phone && (
                <div className="flex gap-2">
                  <Phone className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <div>
                    <p>{thirdParty.phone}</p>
                    {thirdParty.phone_alt && (
                      <p className="text-muted-foreground">{thirdParty.phone_alt}</p>
                    )}
                  </div>
                </div>
              )}
              {thirdParty.email && (
                <div className="flex items-center gap-2">
                  <Mail className="h-4 w-4 text-muted-foreground" />
                  <a href={`mailto:${thirdParty.email}`} className="hover:underline">
                    {thirdParty.email}
                  </a>
                </div>
              )}
              {thirdParty.website && (
                <div className="flex items-center gap-2">
                  <Globe className="h-4 w-4 text-muted-foreground" />
                  <a
                    href={thirdParty.website}
                    target="_blank"
                    rel="noreferrer"
                    className="hover:underline truncate"
                  >
                    {thirdParty.website}
                  </a>
                </div>
              )}
              {thirdParty.address_line1 && (
                <div className="flex gap-2">
                  <MapPin className="h-4 w-4 text-muted-foreground mt-0.5" />
                  <p className="text-muted-foreground">
                    {[
                      thirdParty.address_line1,
                      thirdParty.city,
                      thirdParty.state_province,
                      thirdParty.country,
                    ]
                      .filter(Boolean)
                      .join(', ')}
                  </p>
                </div>
              )}
              {thirdParty.tax_id && (
                <div className="flex items-center gap-2">
                  <FileText className="h-4 w-4 text-muted-foreground" />
                  <span className="text-muted-foreground">TIN: {thirdParty.tax_id}</span>
                </div>
              )}
              {thirdParty.industry && (
                <div className="flex items-center gap-2">
                  <Building2 className="h-4 w-4 text-muted-foreground" />
                  <span>{thirdParty.industry}</span>
                </div>
              )}
              <div className="pt-2 border-t space-y-1 text-xs text-muted-foreground">
                <p>
                  Currency:{' '}
                  <span className="font-medium text-foreground">{thirdParty.currency}</span>
                </p>
                {thirdParty.payment_terms && (
                  <p>
                    Terms:{' '}
                    <span className="font-medium text-foreground">{thirdParty.payment_terms}</span>
                  </p>
                )}
                <p>
                  Credit Limit:{' '}
                  <span className="font-medium text-foreground">
                    {formatCurrency(thirdParty.credit_limit)}
                  </span>
                </p>
              </div>
              {thirdParty.tags && thirdParty.tags.length > 0 && (
                <div className="flex flex-wrap gap-1 pt-2">
                  {thirdParty.tags.map((tag) => (
                    <span key={tag} className="text-xs px-2 py-0.5 bg-muted rounded-full">
                      {tag}
                    </span>
                  ))}
                </div>
              )}
              {thirdParty.notes && (
                <div className="pt-2 border-t">
                  <p className="text-xs text-muted-foreground mb-1">Notes</p>
                  <p className="text-sm">{thirdParty.notes}</p>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Right: Tabs */}
          <div className="md:col-span-2">
            <Tabs defaultValue="contacts">
              <TabsList>
                <TabsTrigger value="contacts">
                  <Users className="h-4 w-4 mr-1" /> Contacts ({thirdParty.contacts.length})
                </TabsTrigger>
                <TabsTrigger value="addresses">
                  <MapPin className="h-4 w-4 mr-1" /> Addresses ({thirdParty.addresses.length})
                </TabsTrigger>
                <TabsTrigger value="orders">
                  <Package className="h-4 w-4 mr-1" /> Orders ({recentOrders.length})
                </TabsTrigger>
              </TabsList>

              {/* Contacts Tab */}
              <TabsContent value="contacts" className="mt-4 space-y-3">
                <div className="flex justify-end">
                  <Button size="sm" onClick={() => setShowAddContact(true)}>
                    <Plus className="h-4 w-4 mr-1" /> Add Contact Person
                  </Button>
                </div>
                {thirdParty.contacts.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-8">
                    No contact persons added yet.
                  </p>
                ) : (
                  thirdParty.contacts.map((c) => (
                    <Card key={c.id}>
                      <CardContent className="p-4 flex items-start justify-between">
                        <div>
                          <div className="flex items-center gap-2">
                            <p className="font-medium">
                              {[c.title, c.first_name, c.last_name].filter(Boolean).join(' ')}
                            </p>
                            {c.is_primary && (
                              <Badge variant="secondary" className="text-xs">
                                Primary
                              </Badge>
                            )}
                          </div>
                          {c.position && (
                            <p className="text-sm text-muted-foreground">
                              {c.position}
                              {c.department ? ` · ${c.department}` : ''}
                            </p>
                          )}
                          <div className="flex gap-3 mt-1">
                            {c.phone && (
                              <span className="text-xs flex items-center gap-1">
                                <Phone className="h-3 w-3" />
                                {c.phone}
                              </span>
                            )}
                            {c.email && (
                              <span className="text-xs flex items-center gap-1">
                                <Mail className="h-3 w-3" />
                                {c.email}
                              </span>
                            )}
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  ))
                )}
              </TabsContent>

              {/* Addresses Tab */}
              <TabsContent value="addresses" className="mt-4 space-y-3">
                <div className="flex justify-end">
                  <Button size="sm" onClick={() => setShowAddAddress(true)}>
                    <Plus className="h-4 w-4 mr-1" /> Add Address
                  </Button>
                </div>
                {thirdParty.addresses.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-8">
                    No addresses added yet.
                  </p>
                ) : (
                  thirdParty.addresses.map((addr) => (
                    <Card key={addr.id}>
                      <CardContent className="p-4">
                        <div className="flex items-center gap-2 mb-1">
                          <span className="text-xs px-2 py-0.5 bg-muted rounded-full capitalize">
                            {addr.type}
                          </span>
                          {addr.label && <span className="text-sm font-medium">{addr.label}</span>}
                          {addr.is_default && (
                            <Badge variant="secondary" className="text-xs">
                              Default
                            </Badge>
                          )}
                        </div>
                        <p className="text-sm">
                          {[
                            addr.address_line1,
                            addr.address_line2,
                            addr.barangay,
                            addr.city,
                            addr.state_province,
                            addr.postal_code,
                            addr.country,
                          ]
                            .filter(Boolean)
                            .join(', ')}
                        </p>
                        {addr.contact_name && (
                          <p className="text-xs text-muted-foreground mt-1">
                            Attn: {addr.contact_name}
                            {addr.contact_phone ? ` · ${addr.contact_phone}` : ''}
                          </p>
                        )}
                      </CardContent>
                    </Card>
                  ))
                )}
              </TabsContent>

              {/* Orders Tab */}
              <TabsContent value="orders" className="mt-4">
                {recentOrders.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-8">No orders found.</p>
                ) : (
                  <div className="space-y-2">
                    {recentOrders.map((o) => (
                      <Card key={o.id}>
                        <CardContent className="p-3 flex items-center justify-between">
                          <div>
                            <p className="font-mono text-sm font-medium">{o.order_number}</p>
                            <p className="text-xs text-muted-foreground">
                              {new Date(o.created_at).toLocaleDateString()}
                            </p>
                          </div>
                          <div className="text-right">
                            <p className="font-medium">{formatCurrency(o.total_amount)}</p>
                            <Badge variant="outline" className="text-xs">
                              {o.status}
                            </Badge>
                          </div>
                        </CardContent>
                      </Card>
                    ))}
                  </div>
                )}
              </TabsContent>
            </Tabs>
          </div>
        </div>
      </div>

      {/* Add Contact Person Dialog */}
      <Dialog open={showAddContact} onOpenChange={setShowAddContact}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Contact Person</DialogTitle>
          </DialogHeader>
          <form onSubmit={submitContact} className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>First Name *</Label>
                <Input
                  value={contactForm.data.first_name}
                  onChange={(e) => contactForm.setData('first_name', e.target.value)}
                  required
                />
              </div>
              <div>
                <Label>Last Name</Label>
                <Input
                  value={contactForm.data.last_name}
                  onChange={(e) => contactForm.setData('last_name', e.target.value)}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Position</Label>
                <Input
                  value={contactForm.data.position}
                  onChange={(e) => contactForm.setData('position', e.target.value)}
                />
              </div>
              <div>
                <Label>Department</Label>
                <Input
                  value={contactForm.data.department}
                  onChange={(e) => contactForm.setData('department', e.target.value)}
                />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Email</Label>
                <Input
                  type="email"
                  value={contactForm.data.email}
                  onChange={(e) => contactForm.setData('email', e.target.value)}
                />
              </div>
              <div>
                <Label>Phone</Label>
                <Input
                  value={contactForm.data.phone}
                  onChange={(e) => contactForm.setData('phone', e.target.value)}
                />
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setShowAddContact(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={contactForm.processing}>
                Save
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Add Address Dialog */}
      <Dialog open={showAddAddress} onOpenChange={setShowAddAddress}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Address</DialogTitle>
          </DialogHeader>
          <form onSubmit={submitAddress} className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>Type *</Label>
                <Select
                  value={addressForm.data.type}
                  onValueChange={(v) => addressForm.setData('type', v)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="billing">Billing</SelectItem>
                    <SelectItem value="shipping">Shipping</SelectItem>
                    <SelectItem value="branch">Branch</SelectItem>
                    <SelectItem value="other">Other</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Label</Label>
                <Input
                  placeholder="e.g. Main Office"
                  value={addressForm.data.label}
                  onChange={(e) => addressForm.setData('label', e.target.value)}
                />
              </div>
            </div>
            <div>
              <Label>Address Line 1 *</Label>
              <Input
                value={addressForm.data.address_line1}
                onChange={(e) => addressForm.setData('address_line1', e.target.value)}
                required
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <Label>City *</Label>
                <Input
                  value={addressForm.data.city}
                  onChange={(e) => addressForm.setData('city', e.target.value)}
                  required
                />
              </div>
              <div>
                <Label>Province</Label>
                <Input
                  value={addressForm.data.state_province}
                  onChange={(e) => addressForm.setData('state_province', e.target.value)}
                />
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setShowAddAddress(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={addressForm.processing}>
                Save
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
