import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import axios from 'axios';

interface Address {
  id: number;
  label: string | null;
  canonical_address: string | null;
  landmark: string | null;
  barangay: string | null;
  city_municipality: string | null;
  province: string | null;
  region: string | null;
  postal_code: string | null;
  contact_name: string | null;
  contact_phone: string | null;
  is_default: boolean;
  source: string | null;
  used_at: string | null;
  created_at: string;
}

interface Customer {
  id: number;
  name: string;
  phone: string;
  facebook_name: string | null;
  canonical_address: string | null;
  total_orders: number;
  addresses: Address[];
  default_address: Address | null;
}

interface Props {
  customer: Customer;
}

export default function CustomersShow({ customer }: Props) {
  const [addresses, setAddresses] = useState<Address[]>(customer.addresses);
  const [form, setForm] = useState({
    label: '',
    canonical_address: '',
    landmark: '',
    barangay: '',
    city_municipality: '',
    province: '',
    region: '',
    postal_code: '',
    contact_name: customer.name,
    contact_phone: customer.phone,
    is_default: false,
  });
  const [loading, setLoading] = useState(false);

  const setDefault = async (addressId: number) => {
    try {
      await axios.patch(`/shop/customers/${customer.id}/addresses/${addressId}/default`);
      const { data } = await axios.get(`/shop/customers/${customer.id}/addresses`);
      setAddresses(data.addresses);
    } catch (e) {
      alert('Failed to update default address.');
    }
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    try {
      await axios.post(`/shop/customers/${customer.id}/addresses`, form);
      const { data } = await axios.get(`/shop/customers/${customer.id}/addresses`);
      setAddresses(data.addresses);
      setForm({
        label: '',
        canonical_address: '',
        landmark: '',
        barangay: '',
        city_municipality: '',
        province: '',
        region: '',
        postal_code: '',
        contact_name: customer.name,
        contact_phone: customer.phone,
        is_default: false,
      });
    } catch (e) {
      alert('Failed to add address.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <AppLayout>
      <Head title={`Customer - ${customer.name}`} />
      <div className="space-y-6 p-4">
        <div className="flex items-center justify-between">
          <h1 className="text-xl font-bold">{customer.name}</h1>
          <Button variant="outline" asChild>
            <Link href="/shop/customers">Back to customers</Link>
          </Button>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Profile</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>
              <strong>Phone:</strong> {customer.phone}
            </p>
            {customer.facebook_name && (
              <p>
                <strong>Facebook:</strong> {customer.facebook_name}
              </p>
            )}
            <p>
              <strong>Total orders:</strong> {customer.total_orders}
            </p>
            <p>
              <strong>Current address:</strong> {customer.canonical_address ?? '-'}
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Address History</CardTitle>
          </CardHeader>
          <CardContent>
            {addresses.length === 0 ? (
              <p className="text-muted-foreground">No address history yet.</p>
            ) : (
              <div className="space-y-3">
                {addresses.map((address) => (
                  <div key={address.id} className="rounded border p-3">
                    <div className="flex items-start justify-between">
                      <div className="space-y-1 text-sm">
                        <p className="font-medium">
                          {address.label ?? 'Address'}
                          {address.is_default && (
                            <Badge className="ml-2" variant="default">
                              Default
                            </Badge>
                          )}
                        </p>
                        <p>{address.canonical_address}</p>
                        <p className="text-muted-foreground">
                          {[
                            address.barangay,
                            address.city_municipality,
                            address.province,
                            address.region,
                          ]
                            .filter(Boolean)
                            .join(', ')}
                        </p>
                        {address.contact_name && (
                          <p>
                            Contact: {address.contact_name} {address.contact_phone}
                          </p>
                        )}
                      </div>
                      {!address.is_default && (
                        <Button size="sm" variant="outline" onClick={() => setDefault(address.id)}>
                          Set default
                        </Button>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Add Address</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={submit} className="space-y-3">
              <div>
                <Label htmlFor="canonical_address">Address</Label>
                <Input
                  id="canonical_address"
                  value={form.canonical_address}
                  onChange={(e) => setForm({ ...form, canonical_address: e.target.value })}
                  required
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="label">Label</Label>
                  <Input
                    id="label"
                    value={form.label}
                    onChange={(e) => setForm({ ...form, label: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="landmark">Landmark</Label>
                  <Input
                    id="landmark"
                    value={form.landmark}
                    onChange={(e) => setForm({ ...form, landmark: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="barangay">Barangay</Label>
                  <Input
                    id="barangay"
                    value={form.barangay}
                    onChange={(e) => setForm({ ...form, barangay: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="city_municipality">City / Municipality</Label>
                  <Input
                    id="city_municipality"
                    value={form.city_municipality}
                    onChange={(e) => setForm({ ...form, city_municipality: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="province">Province</Label>
                  <Input
                    id="province"
                    value={form.province}
                    onChange={(e) => setForm({ ...form, province: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="region">Region</Label>
                  <Input
                    id="region"
                    value={form.region}
                    onChange={(e) => setForm({ ...form, region: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="postal_code">Postal Code</Label>
                  <Input
                    id="postal_code"
                    value={form.postal_code}
                    onChange={(e) => setForm({ ...form, postal_code: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="contact_name">Contact Name</Label>
                  <Input
                    id="contact_name"
                    value={form.contact_name}
                    onChange={(e) => setForm({ ...form, contact_name: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="contact_phone">Contact Phone</Label>
                  <Input
                    id="contact_phone"
                    value={form.contact_phone}
                    onChange={(e) => setForm({ ...form, contact_phone: e.target.value })}
                  />
                </div>
              </div>
              <div className="flex items-center gap-2">
                <Checkbox
                  id="is_default"
                  checked={form.is_default}
                  onCheckedChange={(checked) => setForm({ ...form, is_default: checked === true })}
                />
                <Label htmlFor="is_default">Set as default address</Label>
              </div>
              <Button type="submit" disabled={loading}>
                {loading ? 'Saving...' : 'Add address'}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
