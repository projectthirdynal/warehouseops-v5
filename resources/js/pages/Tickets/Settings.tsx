import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ArrowLeft, Tag, Flag, Plus, Pencil, Trash2, Settings as SettingsIcon } from 'lucide-react';
import AppLayout from '@/layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface CategoryItem {
  id: number;
  name: string;
  slug: string;
  color: string;
  is_active: boolean;
  sort_order: number;
}

interface PriorityItem {
  id: number;
  name: string;
  slug: string;
  color: string;
  level: number;
  is_active: boolean;
  sort_order: number;
}

interface Props {
  categories: CategoryItem[];
  priorities: PriorityItem[];
}

const colorOptions = [
  { value: 'gray', label: 'Gray' },
  { value: 'blue', label: 'Blue' },
  { value: 'green', label: 'Green' },
  { value: 'amber', label: 'Amber' },
  { value: 'orange', label: 'Orange' },
  { value: 'red', label: 'Red' },
  { value: 'purple', label: 'Purple' },
  { value: 'pink', label: 'Pink' },
  { value: 'teal', label: 'Teal' },
];

const colorClassMap: Record<string, string> = {
  gray: 'bg-gray-100 text-gray-700',
  blue: 'bg-blue-100 text-blue-700',
  green: 'bg-green-100 text-green-700',
  amber: 'bg-amber-100 text-amber-700',
  orange: 'bg-orange-100 text-orange-700',
  red: 'bg-red-100 text-red-700',
  purple: 'bg-purple-100 text-purple-700',
  pink: 'bg-pink-100 text-pink-700',
  teal: 'bg-teal-100 text-teal-700',
};

export default function TicketsSettings({ categories, priorities }: Props) {
  const [catDialog, setCatDialog] = useState(false);
  const [priDialog, setPriDialog] = useState(false);
  const [editingCat, setEditingCat] = useState<CategoryItem | null>(null);
  const [editingPri, setEditingPri] = useState<PriorityItem | null>(null);

  const catForm = useForm({
    name: '',
    color: 'gray',
    is_active: true,
    sort_order: 0,
  });

  const priForm = useForm({
    name: '',
    color: 'gray',
    level: 1,
    is_active: true,
    sort_order: 0,
  });

  function openNewCat() {
    setEditingCat(null);
    catForm.reset();
    catForm.setData({ name: '', color: 'gray', is_active: true, sort_order: 0 });
    setCatDialog(true);
  }

  function openEditCat(cat: CategoryItem) {
    setEditingCat(cat);
    catForm.setData({
      name: cat.name,
      color: cat.color,
      is_active: cat.is_active,
      sort_order: cat.sort_order,
    });
    setCatDialog(true);
  }

  function submitCat(e: React.FormEvent) {
    e.preventDefault();
    if (editingCat) {
      router.patch(`/tickets/categories/${editingCat.id}`, catForm.data, {
        preserveScroll: true,
        onSuccess: () => {
          toast.success('Category updated.');
          setCatDialog(false);
        },
        onError: () => toast.error('Failed to update category.'),
      });
    } else {
      router.post('/tickets/categories', catForm.data, {
        preserveScroll: true,
        onSuccess: () => {
          toast.success('Category created.');
          setCatDialog(false);
        },
        onError: () => toast.error('Failed to create category.'),
      });
    }
  }

  function deleteCat(cat: CategoryItem) {
    if (!confirm(`Delete category "${cat.name}"?`)) return;
    router.delete(`/tickets/categories/${cat.id}`, {
      preserveScroll: true,
      onSuccess: () => toast.success('Category deleted.'),
      onError: () => toast.error('Failed to delete category.'),
    });
  }

  function openNewPri() {
    setEditingPri(null);
    priForm.reset();
    priForm.setData({ name: '', color: 'gray', level: 1, is_active: true, sort_order: 0 });
    setPriDialog(true);
  }

  function openEditPri(pri: PriorityItem) {
    setEditingPri(pri);
    priForm.setData({
      name: pri.name,
      color: pri.color,
      level: pri.level,
      is_active: pri.is_active,
      sort_order: pri.sort_order,
    });
    setPriDialog(true);
  }

  function submitPri(e: React.FormEvent) {
    e.preventDefault();
    if (editingPri) {
      router.patch(`/tickets/priorities/${editingPri.id}`, priForm.data, {
        preserveScroll: true,
        onSuccess: () => {
          toast.success('Priority updated.');
          setPriDialog(false);
        },
        onError: () => toast.error('Failed to update priority.'),
      });
    } else {
      router.post('/tickets/priorities', priForm.data, {
        preserveScroll: true,
        onSuccess: () => {
          toast.success('Priority created.');
          setPriDialog(false);
        },
        onError: () => toast.error('Failed to create priority.'),
      });
    }
  }

  function deletePri(pri: PriorityItem) {
    if (!confirm(`Delete priority "${pri.name}"?`)) return;
    router.delete(`/tickets/priorities/${pri.id}`, {
      preserveScroll: true,
      onSuccess: () => toast.success('Priority deleted.'),
      onError: () => toast.error('Failed to delete priority.'),
    });
  }

  return (
    <AppLayout>
      <Head title="Ticket Settings" />

      <div className="space-y-6">
        <div>
          <Button variant="ghost" size="sm" asChild>
            <Link href="/tickets">
              <ArrowLeft className="mr-1.5 h-4 w-4" />
              Back to Tickets
            </Link>
          </Button>
        </div>

        <div className="flex items-center gap-3">
          <SettingsIcon className="h-6 w-6 text-muted-foreground" />
          <div>
            <h1 className="text-xl font-bold font-display tracking-tight">Ticket Settings</h1>
            <p className="text-muted-foreground">Manage ticket categories and priorities</p>
          </div>
        </div>

        <div className="grid gap-6 md:grid-cols-2">
          {/* Categories */}
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <Tag className="h-4 w-4" />
                  Categories
                </CardTitle>
                <Button size="sm" variant="outline" onClick={openNewCat}>
                  <Plus className="mr-1.5 h-3.5 w-3.5" />
                  Add
                </Button>
              </div>
            </CardHeader>
            <CardContent className="space-y-2">
              {categories.map((cat) => (
                <div
                  key={cat.id}
                  className="flex items-center justify-between rounded-md border p-3"
                >
                  <div className="flex items-center gap-3">
                    <Badge className={colorClassMap[cat.color] || colorClassMap.gray}>
                      {cat.name}
                    </Badge>
                    {!cat.is_active && (
                      <span className="text-xs text-muted-foreground">Inactive</span>
                    )}
                    <span className="text-xs text-muted-foreground">Order: {cat.sort_order}</span>
                  </div>
                  <div className="flex items-center gap-1">
                    <Button variant="ghost" size="icon" onClick={() => openEditCat(cat)}>
                      <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button variant="ghost" size="icon" onClick={() => deleteCat(cat)}>
                      <Trash2 className="h-3.5 w-3.5 text-destructive" />
                    </Button>
                  </div>
                </div>
              ))}
              {categories.length === 0 && (
                <p className="text-sm text-muted-foreground italic">No categories yet.</p>
              )}
            </CardContent>
          </Card>

          {/* Priorities */}
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                  <Flag className="h-4 w-4" />
                  Priorities
                </CardTitle>
                <Button size="sm" variant="outline" onClick={openNewPri}>
                  <Plus className="mr-1.5 h-3.5 w-3.5" />
                  Add
                </Button>
              </div>
            </CardHeader>
            <CardContent className="space-y-2">
              {priorities.map((pri) => (
                <div
                  key={pri.id}
                  className="flex items-center justify-between rounded-md border p-3"
                >
                  <div className="flex items-center gap-3">
                    <Badge className={colorClassMap[pri.color] || colorClassMap.gray}>
                      {pri.name}
                    </Badge>
                    <span className="text-xs text-muted-foreground">Level: {pri.level}</span>
                    {!pri.is_active && (
                      <span className="text-xs text-muted-foreground">Inactive</span>
                    )}
                  </div>
                  <div className="flex items-center gap-1">
                    <Button variant="ghost" size="icon" onClick={() => openEditPri(pri)}>
                      <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button variant="ghost" size="icon" onClick={() => deletePri(pri)}>
                      <Trash2 className="h-3.5 w-3.5 text-destructive" />
                    </Button>
                  </div>
                </div>
              ))}
              {priorities.length === 0 && (
                <p className="text-sm text-muted-foreground italic">No priorities yet.</p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {/* Category Dialog */}
      <Dialog open={catDialog} onOpenChange={setCatDialog}>
        <DialogContent className="sm:max-w-[400px]">
          <DialogHeader>
            <DialogTitle>{editingCat ? 'Edit Category' : 'New Category'}</DialogTitle>
            <DialogDescription>
              {editingCat ? 'Update the category details below.' : 'Create a new ticket category.'}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={submitCat} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="cat-name">Name</Label>
              <Input
                id="cat-name"
                value={catForm.data.name}
                onChange={(e) => catForm.setData('name', e.target.value)}
                placeholder="e.g. Billing"
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label>Color</Label>
              <Select value={catForm.data.color} onValueChange={(v) => catForm.setData('color', v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {colorOptions.map((c) => (
                    <SelectItem key={c.value} value={c.value}>
                      {c.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="cat-sort">Sort Order</Label>
                <Input
                  id="cat-sort"
                  type="number"
                  value={catForm.data.sort_order}
                  onChange={(e) => catForm.setData('sort_order', Number(e.target.value))}
                />
              </div>
              <div className="space-y-1.5">
                <Label>Active</Label>
                <Select
                  value={catForm.data.is_active ? 'true' : 'false'}
                  onValueChange={(v) => catForm.setData('is_active', v === 'true')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="true">Yes</SelectItem>
                    <SelectItem value="false">No</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setCatDialog(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={catForm.processing}>
                {catForm.processing ? 'Saving...' : editingCat ? 'Update' : 'Create'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Priority Dialog */}
      <Dialog open={priDialog} onOpenChange={setPriDialog}>
        <DialogContent className="sm:max-w-[400px]">
          <DialogHeader>
            <DialogTitle>{editingPri ? 'Edit Priority' : 'New Priority'}</DialogTitle>
            <DialogDescription>
              {editingPri ? 'Update the priority details below.' : 'Create a new ticket priority.'}
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={submitPri} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="pri-name">Name</Label>
              <Input
                id="pri-name"
                value={priForm.data.name}
                onChange={(e) => priForm.setData('name', e.target.value)}
                placeholder="e.g. Critical"
                required
              />
            </div>
            <div className="space-y-1.5">
              <Label>Color</Label>
              <Select value={priForm.data.color} onValueChange={(v) => priForm.setData('color', v)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {colorOptions.map((c) => (
                    <SelectItem key={c.value} value={c.value}>
                      {c.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-1.5">
                <Label htmlFor="pri-level">Level</Label>
                <Input
                  id="pri-level"
                  type="number"
                  value={priForm.data.level}
                  onChange={(e) => priForm.setData('level', Number(e.target.value))}
                  required
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="pri-sort">Sort</Label>
                <Input
                  id="pri-sort"
                  type="number"
                  value={priForm.data.sort_order}
                  onChange={(e) => priForm.setData('sort_order', Number(e.target.value))}
                />
              </div>
              <div className="space-y-1.5">
                <Label>Active</Label>
                <Select
                  value={priForm.data.is_active ? 'true' : 'false'}
                  onValueChange={(v) => priForm.setData('is_active', v === 'true')}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="true">Yes</SelectItem>
                    <SelectItem value="false">No</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setPriDialog(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={priForm.processing}>
                {priForm.processing ? 'Saving...' : editingPri ? 'Update' : 'Create'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  );
}
