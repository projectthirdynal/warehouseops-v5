import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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

interface Note {
  id: number;
  user_id: number | null;
  user: { id: number; name: string } | null;
  note_type: string;
  body: string;
  tags: string[] | null;
  pinned_until: string | null;
  created_at: string;
}

interface Activity {
  type: 'order' | 'note' | 'message' | 'conversation';
  occurred_at: string;
  title: string;
  description: string;
  metadata: Record<string, unknown>;
}

interface Customer {
  id: number;
  name: string;
  phone: string;
  facebook_name: string | null;
  canonical_address: string | null;
  landmark: string | null;
  barangay: string | null;
  city_municipality: string | null;
  province: string | null;
  region: string | null;
  total_orders: number;
  total_revenue: number;
  average_order_value: number;
  preferred_courier: string | null;
  payment_method: string | null;
  tags: string[] | null;
  addresses: Address[];
  default_address: Address | null;
  notes: Note[];
}

interface Props {
  customer: Customer;
}

export default function CustomersShow({ customer }: Props) {
  const [addresses, setAddresses] = useState<Address[]>(customer.addresses);
  const [notes, setNotes] = useState<Note[]>(customer.notes);
  const [tags, setTags] = useState<string[]>(customer.tags ?? []);
  const [tagInput, setTagInput] = useState('');
  const [noteForm, setNoteForm] = useState({ body: '', tags: '' });
  const [activities, setActivities] = useState<Activity[]>([]);
  const [loadingTimeline, setLoadingTimeline] = useState(true);
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
  const [noteLoading, setNoteLoading] = useState(false);
  const [preferences, setPreferences] = useState({
    preferred_courier: customer.preferred_courier ?? '',
    payment_method: customer.payment_method ?? '',
  });
  const [savingPreferences, setSavingPreferences] = useState(false);
  const [editingProfile, setEditingProfile] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [profileForm, setProfileForm] = useState({
    name: customer.name,
    phone: customer.phone,
    canonical_address: customer.canonical_address ?? '',
    landmark: customer.landmark ?? '',
    barangay: customer.barangay ?? '',
    city_municipality: customer.city_municipality ?? '',
    province: customer.province ?? '',
  });

  const saveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingProfile(true);
    try {
      await axios.patch(`/shop/customers/${customer.id}`, profileForm);
      setEditingProfile(false);
      window.location.reload();
    } catch {
      alert('Failed to update profile.');
    } finally {
      setSavingProfile(false);
    }
  };

  const savePreferences = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingPreferences(true);
    try {
      await axios.patch(`/shop/customers/${customer.id}`, preferences);
      alert('Preferences saved.');
    } catch (e) {
      alert('Failed to save preferences.');
    } finally {
      setSavingPreferences(false);
    }
  };

  const saveTags = async () => {
    try {
      await axios.patch(`/shop/customers/${customer.id}/tags`, { tags });
    } catch (e) {
      alert('Failed to save tags.');
    }
  };

  const addTag = () => {
    const value = tagInput.trim().toLowerCase();
    if (value && !tags.includes(value)) {
      setTags([...tags, value]);
    }
    setTagInput('');
  };

  const removeTag = (tag: string) => {
    setTags(tags.filter((t) => t !== tag));
  };

  const submitNote = async (e: React.FormEvent) => {
    e.preventDefault();
    setNoteLoading(true);
    try {
      const noteTags = noteForm.tags
        .split(',')
        .map((t) => t.trim().toLowerCase())
        .filter(Boolean);
      await axios.post(`/shop/customers/${customer.id}/notes`, {
        body: noteForm.body,
        tags: noteTags,
      });
      const { data } = await axios.get(`/shop/customers/${customer.id}/notes`);
      setNotes(data.notes);
      setNoteForm({ body: '', tags: '' });
    } catch (e) {
      alert('Failed to add note.');
    } finally {
      setNoteLoading(false);
    }
  };

  useEffect(() => {
    axios
      .get(`/shop/customers/${customer.id}/timeline`)
      .then(({ data }) => setActivities(data.activities))
      .catch(() => setActivities([]))
      .finally(() => setLoadingTimeline(false));
  }, [customer.id]);

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
            <div className="flex items-center justify-between">
              <CardTitle>Profile</CardTitle>
              {!editingProfile ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setProfileForm({
                      name: customer.name,
                      phone: customer.phone,
                      canonical_address: customer.canonical_address ?? '',
                      landmark: customer.landmark ?? '',
                      barangay: customer.barangay ?? '',
                      city_municipality: customer.city_municipality ?? '',
                      province: customer.province ?? '',
                    });
                    setEditingProfile(true);
                  }}
                >
                  Edit
                </Button>
              ) : null}
            </div>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            {editingProfile ? (
              <form onSubmit={saveProfile} className="space-y-3">
                <div>
                  <Label htmlFor="profile_name">Name</Label>
                  <Input
                    id="profile_name"
                    value={profileForm.name}
                    onChange={(e) => setProfileForm({ ...profileForm, name: e.target.value })}
                    required
                  />
                </div>
                <div>
                  <Label htmlFor="profile_phone">Phone</Label>
                  <Input
                    id="profile_phone"
                    value={profileForm.phone}
                    onChange={(e) => setProfileForm({ ...profileForm, phone: e.target.value })}
                    required
                  />
                  {profileForm.phone !== customer.phone && profileForm.phone.trim() && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Normalized phone will be updated on save. Linked orders and identities with
                      the old phone will be synced.
                    </p>
                  )}
                </div>
                <div className="border-t pt-3">
                  <p className="mb-2 text-xs font-medium uppercase text-muted-foreground">
                    Address
                  </p>
                  <div className="space-y-3">
                    <div>
                      <Label htmlFor="profile_address">Street Address</Label>
                      <Input
                        id="profile_address"
                        value={profileForm.canonical_address}
                        onChange={(e) =>
                          setProfileForm({ ...profileForm, canonical_address: e.target.value })
                        }
                        placeholder="House no, street, subdivision"
                      />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <div>
                        <Label htmlFor="profile_landmark">Landmark</Label>
                        <Input
                          id="profile_landmark"
                          value={profileForm.landmark}
                          onChange={(e) =>
                            setProfileForm({ ...profileForm, landmark: e.target.value })
                          }
                        />
                      </div>
                      <div>
                        <Label htmlFor="profile_barangay">Barangay</Label>
                        <Input
                          id="profile_barangay"
                          value={profileForm.barangay}
                          onChange={(e) =>
                            setProfileForm({ ...profileForm, barangay: e.target.value })
                          }
                        />
                      </div>
                      <div>
                        <Label htmlFor="profile_city">City / Municipality</Label>
                        <Input
                          id="profile_city"
                          value={profileForm.city_municipality}
                          onChange={(e) =>
                            setProfileForm({ ...profileForm, city_municipality: e.target.value })
                          }
                        />
                      </div>
                      <div>
                        <Label htmlFor="profile_province">Province</Label>
                        <Input
                          id="profile_province"
                          value={profileForm.province}
                          onChange={(e) =>
                            setProfileForm({ ...profileForm, province: e.target.value })
                          }
                        />
                      </div>
                    </div>
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button type="submit" disabled={savingProfile}>
                    {savingProfile ? 'Saving...' : 'Save'}
                  </Button>
                  <Button type="button" variant="outline" onClick={() => setEditingProfile(false)}>
                    Cancel
                  </Button>
                </div>
                {(profileForm.name !== customer.name ||
                  profileForm.phone !== customer.phone ||
                  profileForm.canonical_address !== (customer.canonical_address ?? '') ||
                  profileForm.barangay !== (customer.barangay ?? '') ||
                  profileForm.city_municipality !== (customer.city_municipality ?? '') ||
                  profileForm.province !== (customer.province ?? '') ||
                  profileForm.landmark !== (customer.landmark ?? '')) && (
                  <p className="text-xs text-muted-foreground">
                    Linked orders will be updated with the new name, phone, and address details.
                  </p>
                )}
              </form>
            ) : (
              <>
                <p>
                  <strong>Name:</strong> {customer.name}
                </p>
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
                  <strong>Total revenue:</strong> ₱{customer.total_revenue.toLocaleString()}
                </p>
                <p>
                  <strong>Average order value:</strong> ₱
                  {customer.average_order_value.toLocaleString()}
                </p>
                <p>
                  <strong>Current address:</strong> {customer.canonical_address ?? '-'}
                </p>
                {(customer.barangay ||
                  customer.city_municipality ||
                  customer.province ||
                  customer.region) && (
                  <p className="text-muted-foreground">
                    {[
                      customer.barangay,
                      customer.city_municipality,
                      customer.province,
                      customer.region,
                    ]
                      .filter(Boolean)
                      .join(', ')}
                  </p>
                )}
                {customer.landmark && (
                  <p className="text-muted-foreground">Landmark: {customer.landmark}</p>
                )}
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Preferences</CardTitle>
          </CardHeader>
          <CardContent>
            <form onSubmit={savePreferences} className="space-y-3">
              <div>
                <Label htmlFor="preferred_courier">Preferred courier</Label>
                <Input
                  id="preferred_courier"
                  value={preferences.preferred_courier}
                  onChange={(e) =>
                    setPreferences({ ...preferences, preferred_courier: e.target.value })
                  }
                  placeholder="e.g. FLASH, J&T"
                />
              </div>
              <div>
                <Label htmlFor="payment_method">Payment method</Label>
                <Input
                  id="payment_method"
                  value={preferences.payment_method}
                  onChange={(e) =>
                    setPreferences({ ...preferences, payment_method: e.target.value })
                  }
                  placeholder="e.g. COD, GCash"
                />
              </div>
              <Button type="submit" disabled={savingPreferences}>
                {savingPreferences ? 'Saving...' : 'Save preferences'}
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Tags</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2">
              {tags.map((tag) => (
                <Badge key={tag} variant="secondary" className="gap-1">
                  {tag}
                  <button
                    type="button"
                    className="ml-1 text-muted-foreground hover:text-foreground"
                    onClick={() => removeTag(tag)}
                  >
                    ×
                  </button>
                </Badge>
              ))}
              {tags.length === 0 && <span className="text-sm text-muted-foreground">No tags</span>}
            </div>
            <div className="flex gap-2">
              <Input
                placeholder="Add tag..."
                value={tagInput}
                onChange={(e) => setTagInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), addTag())}
              />
              <Button type="button" variant="outline" onClick={addTag}>
                Add
              </Button>
              <Button type="button" onClick={saveTags}>
                Save tags
              </Button>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Agent Notes</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {notes.length === 0 ? (
              <p className="text-sm text-muted-foreground">No notes yet.</p>
            ) : (
              <div className="space-y-3">
                {notes.map((note) => (
                  <div key={note.id} className="rounded border p-3 text-sm">
                    <div className="mb-1 flex items-center justify-between">
                      <span className="font-medium">{note.user?.name ?? 'System'}</span>
                      <span className="text-muted-foreground">
                        {new Date(note.created_at).toLocaleString()}
                      </span>
                    </div>
                    <p className="whitespace-pre-wrap">{note.body}</p>
                    {note.tags && note.tags.length > 0 && (
                      <div className="mt-2 flex flex-wrap gap-1">
                        {note.tags.map((tag) => (
                          <Badge key={tag} variant="outline">
                            {tag}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
            <form onSubmit={submitNote} className="space-y-2">
              <div>
                <Label htmlFor="note_body">Note</Label>
                <textarea
                  id="note_body"
                  className="w-full rounded-md border px-3 py-2 text-sm"
                  rows={3}
                  value={noteForm.body}
                  onChange={(e) => setNoteForm({ ...noteForm, body: e.target.value })}
                  required
                />
              </div>
              <div>
                <Label htmlFor="note_tags">Tags (comma separated)</Label>
                <Input
                  id="note_tags"
                  value={noteForm.tags}
                  onChange={(e) => setNoteForm({ ...noteForm, tags: e.target.value })}
                />
              </div>
              <Button type="submit" disabled={noteLoading}>
                {noteLoading ? 'Saving...' : 'Add note'}
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Activity Timeline</CardTitle>
          </CardHeader>
          <CardContent>
            {loadingTimeline ? (
              <p className="text-sm text-muted-foreground">Loading timeline...</p>
            ) : activities.length === 0 ? (
              <p className="text-sm text-muted-foreground">No activity yet.</p>
            ) : (
              <div className="relative space-y-4 border-l pl-4">
                {activities.map((activity, index) => (
                  <div key={index} className="relative -ml-[21px] flex gap-3">
                    <div className="mt-1.5 h-2.5 w-2.5 rounded-full bg-primary ring-4 ring-background" />
                    <div className="flex-1 text-sm">
                      <div className="flex items-center justify-between">
                        <span className="font-medium">{activity.title}</span>
                        <Badge variant="outline">{activity.type}</Badge>
                      </div>
                      <p className="text-muted-foreground">{activity.description}</p>
                      <p className="text-xs text-muted-foreground">
                        {new Date(activity.occurred_at).toLocaleString()}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
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
