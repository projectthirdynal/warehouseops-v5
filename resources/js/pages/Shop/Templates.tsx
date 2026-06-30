import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/AppLayout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ArrowLeft, Copy, FileText, Plus, Trash2 } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';

interface Template {
  id: number;
  name: string;
  message: string;
  category: string | null;
  variables: string[];
  is_active: boolean;
  sort_order: number;
  created_at: string;
  creator?: { name: string } | null;
}

interface Props {
  templates: {
    data: Template[];
    current_page: number;
    last_page: number;
    total: number;
  };
}

export default function ShopTemplates({ templates }: Props) {
  const [open, setOpen] = useState(false);

  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    message: '',
    category: '',
    sort_order: '0',
  });

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    post('/shop/templates', {
      onSuccess: () => {
        reset();
        setOpen(false);
      },
    });
  };

  const deleteTemplate = (template: Template) => {
    if (confirm(`Delete template "${template.name}"?`)) {
      router.delete(`/shop/templates/${template.id}`);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
  };

  const categories = ['details', 'confirmation', 'follow_up', 'payment', 'courier'];

  return (
    <AppLayout>
      <Head title="Shop Templates" />

      <div className="space-y-6">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link href="/shop">
              <Button variant="ghost" size="icon">
                <ArrowLeft className="h-5 w-5" />
              </Button>
            </Link>
            <div>
              <h1 className="text-2xl font-bold font-display tracking-tight">
                Shop Reply Templates
              </h1>
              <p className="text-muted-foreground">
                Reusable chat responses for the Shop inbox and conversation desk
              </p>
            </div>
          </div>

          <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="mr-2 h-4 w-4" />
                New Template
              </Button>
            </DialogTrigger>
            <DialogContent>
              <form onSubmit={handleSubmit}>
                <DialogHeader>
                  <DialogTitle>Create Shop Template</DialogTitle>
                  <DialogDescription>
                    Use placeholders like {'{customer_name}'}, {'{address}'}, {'{page_name}'},{' '}
                    {'{phone}'}, and {'{status}'}
                  </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-4">
                  <div className="space-y-2">
                    <Label htmlFor="name">Template Name</Label>
                    <Input
                      id="name"
                      value={data.name}
                      onChange={(event) => setData('name', event.target.value)}
                      placeholder="Same address confirmation"
                    />
                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="category">Category</Label>
                    <div className="flex flex-wrap gap-2">
                      {categories.map((category) => (
                        <Badge
                          key={category}
                          variant={data.category === category ? 'default' : 'outline'}
                          className="cursor-pointer capitalize"
                          onClick={() =>
                            setData('category', data.category === category ? '' : category)
                          }
                        >
                          {category.replace('_', ' ')}
                        </Badge>
                      ))}
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="sort_order">Sort Order</Label>
                    <Input
                      id="sort_order"
                      type="number"
                      min="0"
                      value={data.sort_order}
                      onChange={(event) => setData('sort_order', event.target.value)}
                    />
                    {errors.sort_order && (
                      <p className="text-sm text-destructive">{errors.sort_order}</p>
                    )}
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="message">Message</Label>
                    <Textarea
                      id="message"
                      value={data.message}
                      onChange={(event) => setData('message', event.target.value)}
                      rows={5}
                      className="font-mono"
                      placeholder="Hello po {customer_name}, same address pa rin po ba ito?&#10;{address}"
                    />
                    {errors.message && <p className="text-sm text-destructive">{errors.message}</p>}
                  </div>
                </div>

                <DialogFooter>
                  <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={processing}>
                    Create Template
                  </Button>
                </DialogFooter>
              </form>
            </DialogContent>
          </Dialog>
        </div>

        {templates.data.length === 0 ? (
          <Card>
            <CardContent className="py-12">
              <div className="text-center">
                <FileText className="mx-auto mb-4 h-12 w-12 text-muted-foreground" />
                <h3 className="text-lg font-medium">No templates yet</h3>
                <p className="mt-1 mb-4 text-muted-foreground">
                  Create your first Shop reply template for the inbox desk.
                </p>
                <Button onClick={() => setOpen(true)}>
                  <Plus className="mr-2 h-4 w-4" />
                  Create Template
                </Button>
              </div>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {templates.data.map((template) => (
              <Card key={template.id}>
                <CardHeader className="pb-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <CardTitle className="text-base">{template.name}</CardTitle>
                      <div className="mt-1 flex flex-wrap gap-1">
                        {template.category && (
                          <Badge variant="outline" className="capitalize">
                            {template.category.replace('_', ' ')}
                          </Badge>
                        )}
                        <Badge variant="secondary">#{template.sort_order}</Badge>
                      </div>
                    </div>
                    <div className="flex gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        onClick={() => copyToClipboard(template.message)}
                        title="Copy template"
                      >
                        <Copy className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-destructive"
                        onClick={() => deleteTemplate(template)}
                        title="Delete template"
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="rounded-lg bg-muted p-3">
                    <p className="whitespace-pre-wrap text-sm">{template.message}</p>
                  </div>

                  {template.variables && template.variables.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                      {template.variables.map((variable) => (
                        <Badge key={variable} variant="secondary" className="text-xs">
                          {variable}
                        </Badge>
                      ))}
                    </div>
                  )}

                  <p className="text-xs text-muted-foreground">
                    {template.creator?.name
                      ? `Created by ${template.creator.name} `
                      : 'Starter template '}
                    {formatDistanceToNow(new Date(template.created_at), { addSuffix: true })}
                  </p>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
