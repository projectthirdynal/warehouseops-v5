import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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

interface MergeSuggestion {
  id: number;
  name: string;
  phone: string;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  risk_level: string;
  created_at: string;
}

interface AuditLogEntry {
  id: number;
  action: string;
  field: string | null;
  before_state: Record<string, unknown> | null;
  after_state: Record<string, unknown> | null;
  created_at: string;
  user: { id: number; name: string } | null;
}

interface Customer {
  id: number;
  name: string;
  phone: string;
  facebook_name: string | null;
  profile_image_path: string | null;
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
  preferred_contact_method: string | null;
  preferred_contact_time: string | null;
  marketing_opt_out: boolean;
  language_preference: string | null;
  risk_level: string;
  is_blacklisted: boolean;
  blacklist_reason: string | null;
  blacklisted_at: string | null;
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
  const [noteForm, setNoteForm] = useState({ body: '', tags: '', note_type: 'agent_note' });
  const [activities, setActivities] = useState<Activity[]>([]);
  const [loadingTimeline, setLoadingTimeline] = useState(true);
  const [mergeSuggestions, setMergeSuggestions] = useState<MergeSuggestion[]>([]);
  const [loadingMergeSuggestions, setLoadingMergeSuggestions] = useState(true);
  const [mergingId, setMergingId] = useState<number | null>(null);
  const [auditLogs, setAuditLogs] = useState<AuditLogEntry[]>([]);
  const [loadingAuditLogs, setLoadingAuditLogs] = useState(true);
  const [imageUrl, setImageUrl] = useState<string | null>(
    customer.profile_image_path ? `/storage/${customer.profile_image_path}` : null
  );
  const [uploadingImage, setUploadingImage] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
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
    preferred_contact_method: customer.preferred_contact_method ?? '',
    preferred_contact_time: customer.preferred_contact_time ?? '',
    marketing_opt_out: customer.marketing_opt_out ?? false,
    language_preference: customer.language_preference ?? '',
  });
  const [savingPreferences, setSavingPreferences] = useState(false);
  const [editingProfile, setEditingProfile] = useState(false);
  const [savingProfile, setSavingProfile] = useState(false);
  const [blacklistReason, setBlacklistReason] = useState('');
  const [savingBlacklist, setSavingBlacklist] = useState(false);
  const [riskLevel, setRiskLevel] = useState(customer.risk_level ?? 'LOW');
  const [savingRiskLevel, setSavingRiskLevel] = useState(false);
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
      await axios.patch(`/shop/customers/${customer.id}/preferences`, preferences);
      alert('Preferences saved.');
    } catch {
      alert('Failed to save preferences.');
    } finally {
      setSavingPreferences(false);
    }
  };

  const saveRiskLevel = async () => {
    setSavingRiskLevel(true);
    try {
      await axios.patch(`/shop/customers/${customer.id}/risk-level`, { risk_level: riskLevel });
      window.location.reload();
    } catch {
      alert('Failed to update risk level.');
    } finally {
      setSavingRiskLevel(false);
    }
  };

  const toggleBlacklist = async (blacklist: boolean) => {
    setSavingBlacklist(true);
    try {
      await axios.patch(`/shop/customers/${customer.id}/blacklist`, {
        blacklist,
        reason: blacklist ? blacklistReason : undefined,
      });
      setBlacklistReason('');
      window.location.reload();
    } catch {
      alert('Failed to update blacklist status.');
    } finally {
      setSavingBlacklist(false);
    }
  };

  const saveTags = async (newTags: string[]) => {
    try {
      await axios.patch(`/shop/customers/${customer.id}/tags`, { tags: newTags });
    } catch {
      alert('Failed to save tags.');
    }
  };

  const addTag = () => {
    const value = tagInput.trim().toLowerCase();
    if (value && !tags.includes(value)) {
      const newTags = [...tags, value];
      setTags(newTags);
      saveTags(newTags);
    }
    setTagInput('');
  };

  const removeTag = (tag: string) => {
    const newTags = tags.filter((t) => t !== tag);
    setTags(newTags);
    saveTags(newTags);
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
        note_type: noteForm.note_type,
      });
      const { data } = await axios.get(`/shop/customers/${customer.id}/notes`);
      setNotes(data.notes);
      setNoteForm({ body: '', tags: '', note_type: 'agent_note' });
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

  useEffect(() => {
    axios
      .get(`/shop/customers/${customer.id}/merge-suggestions`)
      .then(({ data }) => setMergeSuggestions(data.suggestions))
      .catch(() => setMergeSuggestions([]))
      .finally(() => setLoadingMergeSuggestions(false));
  }, [customer.id]);

  useEffect(() => {
    axios
      .get(`/shop/customers/${customer.id}/audit-logs`)
      .then(({ data }) => setAuditLogs(data.logs))
      .catch(() => setAuditLogs([]))
      .finally(() => setLoadingAuditLogs(false));
  }, [customer.id]);

  const handleImageUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploadingImage(true);
    try {
      const formData = new FormData();
      formData.append('image', file);
      const { data } = await axios.post(`/shop/customers/${customer.id}/image`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setImageUrl(data.profile_image_url);
    } catch {
      alert('Failed to upload image.');
    } finally {
      setUploadingImage(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const deleteImage = async () => {
    if (!confirm('Remove profile image?')) return;
    try {
      await axios.delete(`/shop/customers/${customer.id}/image`);
      setImageUrl(null);
    } catch {
      alert('Failed to remove image.');
    }
  };

  const mergeCustomer = async (sourceId: number) => {
    if (
      !confirm(
        'Merge this customer into the current one? This will reassign all orders, identities, and conversations, then soft-delete the merged customer.'
      )
    ) {
      return;
    }
    setMergingId(sourceId);
    try {
      await axios.post(`/shop/customers/${customer.id}/merge-suggestions/${sourceId}`);
      window.location.reload();
    } catch {
      alert('Failed to merge customers.');
    } finally {
      setMergingId(null);
    }
  };

  const deleteNote = async (noteId: number) => {
    if (!confirm('Delete this note?')) return;
    try {
      await axios.delete(`/shop/customers/${customer.id}/notes/${noteId}`);
      setNotes(notes.filter((n) => n.id !== noteId));
    } catch {
      alert('Failed to delete note.');
    }
  };

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
          <div className="flex items-center gap-3">
            <div className="relative">
              {imageUrl ? (
                <img
                  src={imageUrl}
                  alt={customer.name}
                  className="h-12 w-12 rounded-full object-cover"
                />
              ) : (
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-lg font-semibold text-muted-foreground">
                  {customer.name.charAt(0).toUpperCase()}
                </div>
              )}
            </div>
            <h1 className="text-xl font-bold">{customer.name}</h1>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => (window.location.href = `/shop/customers/${customer.id}/export`)}
            >
              Export
            </Button>
            <Button variant="outline" asChild>
              <Link href="/shop/customers">Back to customers</Link>
            </Button>
          </div>
        </div>

        <Card>
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>Profile</CardTitle>
              <div className="flex items-center gap-2">
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="hidden"
                  onChange={handleImageUpload}
                />
                <Button
                  variant="outline"
                  size="sm"
                  disabled={uploadingImage}
                  onClick={() => fileInputRef.current?.click()}
                >
                  {uploadingImage ? 'Uploading...' : imageUrl ? 'Change Photo' : 'Upload Photo'}
                </Button>
                {imageUrl && (
                  <Button variant="outline" size="sm" onClick={deleteImage}>
                    Remove
                  </Button>
                )}
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
              <div className="border-t pt-3">
                <p className="mb-2 text-xs font-medium uppercase text-muted-foreground">
                  Communication
                </p>
                <div className="space-y-3">
                  <div>
                    <Label htmlFor="preferred_contact_method">Preferred contact method</Label>
                    <Select
                      value={preferences.preferred_contact_method || 'none'}
                      onValueChange={(value) =>
                        setPreferences({
                          ...preferences,
                          preferred_contact_method: value === 'none' ? '' : value,
                        })
                      }
                    >
                      <SelectTrigger id="preferred_contact_method">
                        <SelectValue placeholder="No preference" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">No preference</SelectItem>
                        <SelectItem value="messenger">Facebook Messenger</SelectItem>
                        <SelectItem value="sms">SMS</SelectItem>
                        <SelectItem value="phone">Phone Call</SelectItem>
                        <SelectItem value="email">Email</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label htmlFor="preferred_contact_time">Preferred contact time</Label>
                    <Select
                      value={preferences.preferred_contact_time || 'none'}
                      onValueChange={(value) =>
                        setPreferences({
                          ...preferences,
                          preferred_contact_time: value === 'none' ? '' : value,
                        })
                      }
                    >
                      <SelectTrigger id="preferred_contact_time">
                        <SelectValue placeholder="Anytime" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">No preference</SelectItem>
                        <SelectItem value="morning">Morning (8AM–12PM)</SelectItem>
                        <SelectItem value="afternoon">Afternoon (12PM–5PM)</SelectItem>
                        <SelectItem value="evening">Evening (5PM–9PM)</SelectItem>
                        <SelectItem value="anytime">Anytime</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div>
                    <Label htmlFor="language_preference">Language preference</Label>
                    <Select
                      value={preferences.language_preference || 'none'}
                      onValueChange={(value) =>
                        setPreferences({
                          ...preferences,
                          language_preference: value === 'none' ? '' : value,
                        })
                      }
                    >
                      <SelectTrigger id="language_preference">
                        <SelectValue placeholder="No preference" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">No preference</SelectItem>
                        <SelectItem value="en">English</SelectItem>
                        <SelectItem value="fil">Filipino</SelectItem>
                        <SelectItem value="ceb">Cebuano</SelectItem>
                        <SelectItem value="hil">Hiligaynon</SelectItem>
                        <SelectItem value="ilo">Ilocano</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="flex items-center gap-2">
                    <Checkbox
                      id="marketing_opt_out"
                      checked={preferences.marketing_opt_out}
                      onCheckedChange={(checked) =>
                        setPreferences({
                          ...preferences,
                          marketing_opt_out: checked === true,
                        })
                      }
                    />
                    <Label htmlFor="marketing_opt_out" className="text-sm font-normal">
                      Opt out of marketing messages
                    </Label>
                  </div>
                </div>
              </div>
              <Button type="submit" disabled={savingPreferences}>
                {savingPreferences ? 'Saving...' : 'Save preferences'}
              </Button>
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Blacklist</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {customer.is_blacklisted ? (
              <>
                <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm">
                  <p className="font-medium text-destructive">Customer is blacklisted</p>
                  {customer.blacklist_reason && (
                    <p className="mt-1 text-muted-foreground">
                      Reason: {customer.blacklist_reason}
                    </p>
                  )}
                  {customer.blacklisted_at && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Since: {new Date(customer.blacklisted_at).toLocaleString()}
                    </p>
                  )}
                </div>
                <Button
                  variant="outline"
                  disabled={savingBlacklist}
                  onClick={() => toggleBlacklist(false)}
                >
                  {savingBlacklist ? 'Processing...' : 'Remove from blacklist'}
                </Button>
              </>
            ) : (
              <>
                <p className="text-sm text-muted-foreground">
                  This customer is not blacklisted. Blacklisting will block them from placing new
                  orders.
                </p>
                <div>
                  <Label htmlFor="blacklist_reason">Reason (optional)</Label>
                  <Input
                    id="blacklist_reason"
                    value={blacklistReason}
                    onChange={(e) => setBlacklistReason(e.target.value)}
                    placeholder="Reason for blacklisting"
                  />
                </div>
                <Button
                  variant="destructive"
                  disabled={savingBlacklist}
                  onClick={() => toggleBlacklist(true)}
                >
                  {savingBlacklist ? 'Processing...' : 'Blacklist customer'}
                </Button>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Risk Level</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex items-center gap-2">
              <Badge variant={customer.is_blacklisted ? 'destructive' : 'secondary'}>
                {customer.risk_level}
              </Badge>
              {customer.is_blacklisted && (
                <span className="text-xs text-muted-foreground">
                  Risk level is locked while blacklisted. Remove from blacklist to edit.
                </span>
              )}
            </div>
            {!customer.is_blacklisted && (
              <>
                <div>
                  <Label htmlFor="risk_level">Override risk level</Label>
                  <Select value={riskLevel} onValueChange={setRiskLevel}>
                    <SelectTrigger id="risk_level">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="LOW">Low</SelectItem>
                      <SelectItem value="MEDIUM">Medium</SelectItem>
                      <SelectItem value="HIGH">High</SelectItem>
                    </SelectContent>
                  </Select>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Manually override the auto-calculated risk level. This will remain until the
                    next automatic recalculation.
                  </p>
                </div>
                <Button
                  disabled={savingRiskLevel || riskLevel === customer.risk_level}
                  onClick={saveRiskLevel}
                >
                  {savingRiskLevel ? 'Saving...' : 'Save risk level'}
                </Button>
              </>
            )}
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
            </div>
            <p className="text-xs text-muted-foreground">
              Tags are saved automatically when added or removed.
            </p>
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
                      <div className="flex items-center gap-2">
                        <span className="font-medium">{note.user?.name ?? 'System'}</span>
                        {note.note_type && note.note_type !== 'agent_note' && (
                          <Badge variant="outline">{note.note_type}</Badge>
                        )}
                        {note.pinned_until && new Date(note.pinned_until) > new Date() && (
                          <Badge variant="default">Pinned</Badge>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-muted-foreground">
                          {new Date(note.created_at).toLocaleString()}
                        </span>
                        <button
                          type="button"
                          className="text-muted-foreground hover:text-destructive"
                          onClick={() => deleteNote(note.id)}
                        >
                          Delete
                        </button>
                      </div>
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
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <Label htmlFor="note_type">Note type</Label>
                  <Select
                    value={noteForm.note_type}
                    onValueChange={(value) => setNoteForm({ ...noteForm, note_type: value })}
                  >
                    <SelectTrigger id="note_type">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="agent_note">Agent Note</SelectItem>
                      <SelectItem value="customer_feedback">Customer Feedback</SelectItem>
                      <SelectItem value="internal">Internal</SelectItem>
                      <SelectItem value="follow_up">Follow Up</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label htmlFor="note_tags">Tags (comma separated)</Label>
                  <Input
                    id="note_tags"
                    value={noteForm.tags}
                    onChange={(e) => setNoteForm({ ...noteForm, tags: e.target.value })}
                  />
                </div>
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
            <CardTitle>Change Audit</CardTitle>
          </CardHeader>
          <CardContent>
            {loadingAuditLogs ? (
              <p className="text-sm text-muted-foreground">Loading audit log...</p>
            ) : auditLogs.length === 0 ? (
              <p className="text-sm text-muted-foreground">No profile changes recorded yet.</p>
            ) : (
              <div className="space-y-2">
                {auditLogs.map((log) => (
                  <div key={log.id} className="rounded border p-2 text-sm">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <Badge variant="outline">{log.action}</Badge>
                        {log.field && (
                          <span className="text-xs text-muted-foreground">{log.field}</span>
                        )}
                      </div>
                      <span className="text-xs text-muted-foreground">
                        {new Date(log.created_at).toLocaleString()}
                      </span>
                    </div>
                    {log.user && (
                      <p className="mt-1 text-xs text-muted-foreground">by {log.user.name}</p>
                    )}
                    {log.before_state && log.after_state && (
                      <div className="mt-1 text-xs">
                        {Object.keys(log.after_state).map((key) => {
                          const beforeVal = log.before_state?.[key];
                          const afterVal = log.after_state?.[key];
                          if (JSON.stringify(beforeVal) === JSON.stringify(afterVal)) return null;
                          return (
                            <p key={key} className="text-muted-foreground">
                              <span className="font-medium">{key}:</span>{' '}
                              <span className="line-through">{String(beforeVal ?? '—')}</span> →{' '}
                              <span className="font-medium text-foreground">
                                {String(afterVal ?? '—')}
                              </span>
                            </p>
                          );
                        })}
                      </div>
                    )}
                    {'note' in (log.after_state ?? {}) && log.after_state?.note != null && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {String(log.after_state.note)}
                      </p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Merge Duplicates</CardTitle>
          </CardHeader>
          <CardContent>
            {loadingMergeSuggestions ? (
              <p className="text-sm text-muted-foreground">Checking for duplicates...</p>
            ) : mergeSuggestions.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No duplicate customers found. This customer has no other records sharing the same
                normalized phone number.
              </p>
            ) : (
              <div className="space-y-3">
                <p className="text-sm text-muted-foreground">
                  {mergeSuggestions.length} customer{mergeSuggestions.length > 1 ? 's' : ''} share
                  the same normalized phone number. Merging will reassign all orders, identities,
                  and conversations to this customer, then soft-delete the duplicate.
                </p>
                {mergeSuggestions.map((suggestion) => (
                  <div key={suggestion.id} className="rounded border p-3">
                    <div className="flex items-start justify-between">
                      <div className="space-y-1 text-sm">
                        <div className="flex items-center gap-2">
                          <p className="font-medium">{suggestion.name}</p>
                          <Badge
                            variant={
                              suggestion.risk_level === 'BLACKLISTED' ? 'destructive' : 'secondary'
                            }
                          >
                            {suggestion.risk_level}
                          </Badge>
                        </div>
                        <p className="text-muted-foreground">{suggestion.phone}</p>
                        <div className="flex gap-3 text-xs text-muted-foreground">
                          <span>Orders: {suggestion.total_orders}</span>
                          <span>Successful: {suggestion.successful_orders}</span>
                          <span>Returned: {suggestion.returned_orders}</span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                          Created: {new Date(suggestion.created_at).toLocaleDateString()}
                        </p>
                      </div>
                      <Button
                        size="sm"
                        variant="outline"
                        disabled={mergingId === suggestion.id}
                        onClick={() => mergeCustomer(suggestion.id)}
                      >
                        {mergingId === suggestion.id ? 'Merging...' : 'Merge into this customer'}
                      </Button>
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
                        <div className="flex items-center gap-2">
                          <p className="font-medium">{address.label ?? 'Address'}</p>
                          {address.is_default && <Badge variant="default">Default</Badge>}
                          {address.source && <Badge variant="outline">{address.source}</Badge>}
                        </div>
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
                        {address.landmark && (
                          <p className="text-muted-foreground">Landmark: {address.landmark}</p>
                        )}
                        {address.contact_name && (
                          <p>
                            Contact: {address.contact_name} {address.contact_phone}
                          </p>
                        )}
                        <div className="flex gap-3 text-xs text-muted-foreground">
                          {address.created_at && (
                            <span>
                              Recorded: {new Date(address.created_at).toLocaleDateString()}
                            </span>
                          )}
                          {address.used_at && (
                            <span>Last used: {new Date(address.used_at).toLocaleDateString()}</span>
                          )}
                        </div>
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
