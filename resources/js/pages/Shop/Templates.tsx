import { useState, useEffect } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
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
import {
  ArrowLeft,
  Copy,
  FileText,
  Image,
  LayoutGrid,
  MousePointerClick,
  Plus,
  Trash2,
  Eye,
  Package,
  X,
} from 'lucide-react';
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

interface RichMediaTemplate {
  id: number;
  title: string;
  content: string;
  media_type: 'button' | 'card' | 'carousel';
  media_type_label: string;
  media_config: any;
  category: string | null;
  is_active: boolean;
  creator: string | null;
  updated_at: string;
}

interface RichMediaStats {
  total: number;
  text: number;
  button: number;
  card: number;
  carousel: number;
  rich_total: number;
  recent: Array<{
    id: number;
    title: string;
    media_type: string;
    media_type_label: string;
    card_count: number;
    button_count: number;
    updated_at: string;
    creator: string | null;
  }>;
}

interface ButtonItem {
  title: string;
  type: 'postback' | 'web_url' | 'phone_number';
  value: string;
}

interface CardItem {
  title: string;
  subtitle: string;
  image_url?: string;
  default_action_url?: string;
  buttons?: ButtonItem[];
}

interface Props {
  templates: {
    data: Template[];
    current_page: number;
    last_page: number;
    total: number;
  };
  rich_media_templates?: RichMediaTemplate[];
  rich_media_stats?: RichMediaStats;
  media_types?: Record<string, string>;
}

export default function ShopTemplates({
  templates,
  rich_media_templates = [],
  rich_media_stats,
  media_types = {},
}: Props) {
  const [open, setOpen] = useState(false);
  const [richMediaOpen, setRichMediaOpen] = useState(false);
  const [activeTab, setActiveTab] = useState<'text' | 'rich'>('text');

  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    message: '',
    category: '',
    sort_order: '0',
  });

  // Rich media form state
  const [rmTitle, setRmTitle] = useState('');
  const [rmContent, setRmContent] = useState('');
  const [rmMediaType, setRmMediaType] = useState<'button' | 'card' | 'carousel'>('button');
  const [rmCategory, setRmCategory] = useState('');
  const [rmButtons, setRmButtons] = useState<ButtonItem[]>([
    { title: '', type: 'postback', value: '' },
  ]);
  const [rmCards, setRmCards] = useState<CardItem[]>([
    { title: '', subtitle: '', buttons: [{ title: 'Order This', type: 'postback', value: '' }] },
  ]);
  const [rmCardTitle, setRmCardTitle] = useState('');
  const [rmCardSubtitle, setRmCardSubtitle] = useState('');
  const [rmCardImage, setRmCardImage] = useState('');
  const [rmCardActionUrl, setRmCardActionUrl] = useState('');
  const [rmCardButtons, setRmCardButtons] = useState<ButtonItem[]>([]);
  const [previewData, setPreviewData] = useState<any>(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [productIds, setProductIds] = useState('');
  const [discountPercent, setDiscountPercent] = useState('');
  const [generating, setGenerating] = useState(false);
  const [richMediaList, setRichMediaList] = useState<RichMediaTemplate[]>(rich_media_templates);
  const [stats, setStats] = useState<RichMediaStats | null>(rich_media_stats ?? null);

  useEffect(() => {
    setRichMediaList(rich_media_templates);
    setStats(rich_media_stats ?? null);
  }, [rich_media_templates, rich_media_stats]);

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

  // Rich media helpers
  const mediaTypeIcon = (type: string) => {
    switch (type) {
      case 'button':
        return <MousePointerClick className="h-4 w-4" />;
      case 'card':
        return <Image className="h-4 w-4" />;
      case 'carousel':
        return <LayoutGrid className="h-4 w-4" />;
      default:
        return <FileText className="h-4 w-4" />;
    }
  };

  const addButton = () => {
    if (rmButtons.length < 3) {
      setRmButtons([...rmButtons, { title: '', type: 'postback', value: '' }]);
    }
  };

  const removeButton = (index: number) => {
    setRmButtons(rmButtons.filter((_, i) => i !== index));
  };

  const updateButton = (index: number, field: keyof ButtonItem, value: string) => {
    const updated = [...rmButtons];
    updated[index] = { ...updated[index], [field]: value };
    setRmButtons(updated);
  };

  const addCard = () => {
    if (rmCards.length < 10) {
      setRmCards([
        ...rmCards,
        {
          title: '',
          subtitle: '',
          buttons: [{ title: 'Order This', type: 'postback', value: '' }],
        },
      ]);
    }
  };

  const removeCard = (index: number) => {
    setRmCards(rmCards.filter((_, i) => i !== index));
  };

  const updateCard = (index: number, field: keyof CardItem, value: string) => {
    const updated = [...rmCards];
    updated[index] = { ...updated[index], [field]: value };
    setRmCards(updated);
  };

  const updateCardButton = (
    cardIndex: number,
    btnIndex: number,
    field: keyof ButtonItem,
    value: string
  ) => {
    const updated = [...rmCards];
    if (!updated[cardIndex].buttons) updated[cardIndex].buttons = [];
    updated[cardIndex].buttons![btnIndex] = {
      ...updated[cardIndex].buttons![btnIndex],
      [field]: value,
    };
    setRmCards(updated);
  };

  const addCardButton = (cardIndex: number) => {
    const updated = [...rmCards];
    if (!updated[cardIndex].buttons) updated[cardIndex].buttons = [];
    if (updated[cardIndex].buttons!.length < 3) {
      updated[cardIndex].buttons!.push({ title: '', type: 'postback', value: '' });
      setRmCards(updated);
    }
  };

  const removeCardButton = (cardIndex: number, btnIndex: number) => {
    const updated = [...rmCards];
    updated[cardIndex].buttons = updated[cardIndex].buttons!.filter((_, i) => i !== btnIndex);
    setRmCards(updated);
  };

  const buildConfig = (): any => {
    switch (rmMediaType) {
      case 'button':
        return { buttons: rmButtons.filter((b) => b.title && b.value) };
      case 'card':
        return {
          title: rmCardTitle,
          subtitle: rmCardSubtitle,
          image_url: rmCardImage || undefined,
          default_action_url: rmCardActionUrl || undefined,
          buttons: rmCardButtons.filter((b) => b.title && b.value),
        };
      case 'carousel':
        return { cards: rmCards.filter((c) => c.title && c.subtitle) };
      default:
        return null;
    }
  };

  const handlePreview = async () => {
    setPreviewLoading(true);
    try {
      const res = await axios.post('/shop/rich-media-templates/preview', {
        media_type: rmMediaType,
        content: rmContent,
        media_config: buildConfig(),
      });
      setPreviewData(res.data);
    } catch (err: any) {
      toast.error('Preview failed', {
        description: err.response?.data?.errors?.join(', ') ?? 'Validation error',
      });
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleSaveRichMedia = async () => {
    const config = buildConfig();
    try {
      await axios.post('/shop/rich-media-templates', {
        title: rmTitle,
        content: rmContent,
        media_type: rmMediaType,
        media_config: config,
        category: rmCategory || null,
        is_active: true,
      });
      toast.success('Rich media template created');
      setRichMediaOpen(false);
      resetRichMediaForm();
      router.reload({ only: ['rich_media_templates', 'rich_media_stats'] });
    } catch (err: any) {
      toast.error('Failed to create template', {
        description: err.response?.data?.errors?.join(', ') ?? 'Unknown error',
      });
    }
  };

  const handleDeleteRichMedia = async (id: number, title: string) => {
    if (!confirm(`Delete rich media template "${title}"?`)) return;
    try {
      await axios.delete(`/shop/rich-media-templates/${id}`);
      toast.success('Rich media template deleted');
      setRichMediaList(richMediaList.filter((t) => t.id !== id));
      router.reload({ only: ['rich_media_templates', 'rich_media_stats'] });
    } catch {
      toast.error('Failed to delete template');
    }
  };

  const handleGenerateCarousel = async () => {
    const ids = productIds
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s);
    if (ids.length === 0) {
      toast.error('Enter at least one product ID');
      return;
    }
    setGenerating(true);
    try {
      const res = await axios.post('/shop/rich-media-templates/generate-carousel', {
        product_ids: ids.map(Number),
        discount_percent: discountPercent ? Number(discountPercent) : undefined,
      });
      setRmMediaType('carousel');
      const cards = res.data.media_config.cards as CardItem[];
      setRmCards(cards);
      toast.success(`Generated carousel with ${cards.length} cards`);
    } catch (err: any) {
      toast.error('Failed to generate carousel', {
        description: err.response?.data?.message ?? 'Unknown error',
      });
    } finally {
      setGenerating(false);
    }
  };

  const resetRichMediaForm = () => {
    setRmTitle('');
    setRmContent('');
    setRmMediaType('button');
    setRmCategory('');
    setRmButtons([{ title: '', type: 'postback', value: '' }]);
    setRmCards([
      { title: '', subtitle: '', buttons: [{ title: 'Order This', type: 'postback', value: '' }] },
    ]);
    setRmCardTitle('');
    setRmCardSubtitle('');
    setRmCardImage('');
    setRmCardActionUrl('');
    setRmCardButtons([]);
    setPreviewData(null);
    setProductIds('');
    setDiscountPercent('');
  };

  return (
    <AppLayout>
      <Head title="Shop Templates" />

      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Link href="/shop">
              <Button variant="ghost" size="icon">
                <ArrowLeft className="h-5 w-5" />
              </Button>
            </Link>
            <div>
              <h1 className="text-xl font-bold font-display tracking-tight">
                Shop Reply Templates
              </h1>
              <p className="text-muted-foreground">
                Reusable chat responses for the Shop inbox and conversation desk
              </p>
            </div>
          </div>

          <div className="flex gap-2">
            <Dialog open={richMediaOpen} onOpenChange={setRichMediaOpen}>
              <DialogTrigger asChild>
                <Button variant="outline">
                  <LayoutGrid className="mr-1.5 h-4 w-4" />
                  New Rich Media Template
                </Button>
              </DialogTrigger>
              <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                  <DialogTitle>Create Rich Media Template</DialogTitle>
                  <DialogDescription>
                    Build interactive buttons, product cards, or carousels for Facebook Messenger
                  </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                  {/* Media Type Selector */}
                  <div className="space-y-2">
                    <Label>Media Type</Label>
                    <div className="flex gap-2">
                      {(['button', 'card', 'carousel'] as const).map((type) => (
                        <Badge
                          key={type}
                          variant={rmMediaType === type ? 'default' : 'outline'}
                          className="cursor-pointer capitalize"
                          onClick={() => setRmMediaType(type)}
                        >
                          {mediaTypeIcon(type)}
                          <span className="ml-1">{media_types[type] ?? type}</span>
                        </Badge>
                      ))}
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="rm_title">Template Title</Label>
                      <Input
                        id="rm_title"
                        value={rmTitle}
                        onChange={(e) => setRmTitle(e.target.value)}
                        placeholder="Product Showcase"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="rm_category">Category (optional)</Label>
                      <Input
                        id="rm_category"
                        value={rmCategory}
                        onChange={(e) => setRmCategory(e.target.value)}
                        placeholder="product_showcase"
                      />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="rm_content">Text Content</Label>
                    <Textarea
                      id="rm_content"
                      value={rmContent}
                      onChange={(e) => setRmContent(e.target.value)}
                      rows={3}
                      placeholder="Check out our latest products!"
                    />
                    <p className="text-xs text-muted-foreground">
                      For button templates, this text appears above the buttons. For
                      cards/carousels, it's the fallback text.
                    </p>
                  </div>

                  {/* Button Config */}
                  {rmMediaType === 'button' && (
                    <div className="space-y-3 rounded-lg border p-3">
                      <Label>Buttons (max 3)</Label>
                      {rmButtons.map((btn, i) => (
                        <div key={i} className="flex gap-2">
                          <Input
                            placeholder="Button title"
                            value={btn.title}
                            onChange={(e) => updateButton(i, 'title', e.target.value)}
                            className="flex-1"
                          />
                          <select
                            className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                            value={btn.type}
                            onChange={(e) => updateButton(i, 'type', e.target.value)}
                          >
                            <option value="postback">Postback</option>
                            <option value="web_url">URL</option>
                            <option value="phone_number">Phone</option>
                          </select>
                          <Input
                            placeholder="Value / URL / Payload"
                            value={btn.value}
                            onChange={(e) => updateButton(i, 'value', e.target.value)}
                            className="flex-1"
                          />
                          {rmButtons.length > 1 && (
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-9 w-9 text-destructive"
                              onClick={() => removeButton(i)}
                            >
                              <X className="h-4 w-4" />
                            </Button>
                          )}
                        </div>
                      ))}
                      {rmButtons.length < 3 && (
                        <Button variant="outline" size="sm" onClick={addButton}>
                          <Plus className="mr-1 h-3 w-3" />
                          Add Button
                        </Button>
                      )}
                    </div>
                  )}

                  {/* Card Config */}
                  {rmMediaType === 'card' && (
                    <div className="space-y-3 rounded-lg border p-3">
                      <Label>Product Card</Label>
                      <Input
                        placeholder="Card title"
                        value={rmCardTitle}
                        onChange={(e) => setRmCardTitle(e.target.value)}
                      />
                      <Input
                        placeholder="Card subtitle"
                        value={rmCardSubtitle}
                        onChange={(e) => setRmCardSubtitle(e.target.value)}
                      />
                      <Input
                        placeholder="Image URL or /storage/path (optional)"
                        value={rmCardImage}
                        onChange={(e) => setRmCardImage(e.target.value)}
                      />
                      <Input
                        placeholder="Default action URL (optional)"
                        value={rmCardActionUrl}
                        onChange={(e) => setRmCardActionUrl(e.target.value)}
                      />
                      <div className="space-y-2">
                        <Label>Card Buttons (max 3)</Label>
                        {rmCardButtons.map((btn, i) => (
                          <div key={i} className="flex gap-2">
                            <Input
                              placeholder="Button title"
                              value={btn.title}
                              onChange={(e) =>
                                setRmCardButtons(
                                  rmCardButtons.map((b, j) =>
                                    j === i ? { ...b, title: e.target.value } : b
                                  )
                                )
                              }
                              className="flex-1"
                            />
                            <select
                              className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                              value={btn.type}
                              onChange={(e) =>
                                setRmCardButtons(
                                  rmCardButtons.map((b, j) =>
                                    j === i
                                      ? { ...b, type: e.target.value as ButtonItem['type'] }
                                      : b
                                  )
                                )
                              }
                            >
                              <option value="postback">Postback</option>
                              <option value="web_url">URL</option>
                              <option value="phone_number">Phone</option>
                            </select>
                            <Input
                              placeholder="Value"
                              value={btn.value}
                              onChange={(e) =>
                                setRmCardButtons(
                                  rmCardButtons.map((b, j) =>
                                    j === i ? { ...b, value: e.target.value } : b
                                  )
                                )
                              }
                              className="flex-1"
                            />
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-9 w-9 text-destructive"
                              onClick={() =>
                                setRmCardButtons(rmCardButtons.filter((_, j) => j !== i))
                              }
                            >
                              <X className="h-4 w-4" />
                            </Button>
                          </div>
                        ))}
                        {rmCardButtons.length < 3 && (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                              setRmCardButtons([
                                ...rmCardButtons,
                                { title: '', type: 'postback', value: '' },
                              ])
                            }
                          >
                            <Plus className="mr-1 h-3 w-3" />
                            Add Button
                          </Button>
                        )}
                      </div>
                    </div>
                  )}

                  {/* Carousel Config */}
                  {rmMediaType === 'carousel' && (
                    <div className="space-y-3 rounded-lg border p-3">
                      <div className="flex items-center justify-between">
                        <Label>Carousel Cards (max 10)</Label>
                        <div className="flex gap-2">
                          <Input
                            placeholder="Product IDs (1,2,3)"
                            value={productIds}
                            onChange={(e) => setProductIds(e.target.value)}
                            className="w-40 text-xs"
                          />
                          <Input
                            placeholder="Discount %"
                            type="number"
                            value={discountPercent}
                            onChange={(e) => setDiscountPercent(e.target.value)}
                            className="w-20 text-xs"
                          />
                          <Button
                            variant="secondary"
                            size="sm"
                            onClick={handleGenerateCarousel}
                            disabled={generating}
                          >
                            <Package className="mr-1 h-3 w-3" />
                            {generating ? 'Generating...' : 'Generate'}
                          </Button>
                        </div>
                      </div>
                      {rmCards.map((card, i) => (
                        <div key={i} className="space-y-2 rounded-lg border p-2">
                          <div className="flex items-center justify-between">
                            <span className="text-xs font-medium">Card {i + 1}</span>
                            {rmCards.length > 1 && (
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7 text-destructive"
                                onClick={() => removeCard(i)}
                              >
                                <X className="h-3 w-3" />
                              </Button>
                            )}
                          </div>
                          <Input
                            placeholder="Card title"
                            value={card.title}
                            onChange={(e) => updateCard(i, 'title', e.target.value)}
                          />
                          <Input
                            placeholder="Card subtitle"
                            value={card.subtitle}
                            onChange={(e) => updateCard(i, 'subtitle', e.target.value)}
                          />
                          <Input
                            placeholder="Image URL (optional)"
                            value={card.image_url ?? ''}
                            onChange={(e) => updateCard(i, 'image_url', e.target.value)}
                          />
                          <Input
                            placeholder="Default action URL (optional)"
                            value={card.default_action_url ?? ''}
                            onChange={(e) => updateCard(i, 'default_action_url', e.target.value)}
                          />
                          {card.buttons?.map((btn, j) => (
                            <div key={j} className="flex gap-1 pl-2">
                              <Input
                                placeholder="Btn title"
                                value={btn.title}
                                onChange={(e) => updateCardButton(i, j, 'title', e.target.value)}
                                className="flex-1 text-xs"
                              />
                              <select
                                className="h-9 rounded-md border border-input bg-background px-2 text-xs"
                                value={btn.type}
                                onChange={(e) => updateCardButton(i, j, 'type', e.target.value)}
                              >
                                <option value="postback">Postback</option>
                                <option value="web_url">URL</option>
                                <option value="phone_number">Phone</option>
                              </select>
                              <Input
                                placeholder="Value"
                                value={btn.value}
                                onChange={(e) => updateCardButton(i, j, 'value', e.target.value)}
                                className="flex-1 text-xs"
                              />
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7 text-destructive"
                                onClick={() => removeCardButton(i, j)}
                              >
                                <X className="h-3 w-3" />
                              </Button>
                            </div>
                          ))}
                          {(!card.buttons || card.buttons.length < 3) && (
                            <Button
                              variant="ghost"
                              size="sm"
                              className="text-xs"
                              onClick={() => addCardButton(i)}
                            >
                              <Plus className="mr-1 h-3 w-3" />
                              Add Button
                            </Button>
                          )}
                        </div>
                      ))}
                      {rmCards.length < 10 && (
                        <Button variant="outline" size="sm" onClick={addCard}>
                          <Plus className="mr-1 h-3 w-3" />
                          Add Card
                        </Button>
                      )}
                    </div>
                  )}

                  {/* Preview */}
                  {previewData && (
                    <div className="rounded-lg border bg-muted p-3">
                      <p className="mb-2 text-sm font-medium">Preview Payload</p>
                      <pre className="max-h-48 overflow-auto text-xs">
                        {JSON.stringify(previewData.payload, null, 2)}
                      </pre>
                      {previewData.errors && previewData.errors.length > 0 && (
                        <div className="mt-2 space-y-1">
                          {previewData.errors.map((err: string, i: number) => (
                            <p key={i} className="text-xs text-destructive">
                              {err}
                            </p>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </div>

                <DialogFooter>
                  <Button variant="outline" onClick={handlePreview} disabled={previewLoading}>
                    <Eye className="mr-1.5 h-4 w-4" />
                    {previewLoading ? 'Previewing...' : 'Preview'}
                  </Button>
                  <Button type="button" variant="outline" onClick={() => setRichMediaOpen(false)}>
                    Cancel
                  </Button>
                  <Button
                    type="button"
                    onClick={handleSaveRichMedia}
                    disabled={!rmTitle || !rmContent}
                  >
                    Create Template
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>

            <Dialog open={open} onOpenChange={setOpen}>
              <DialogTrigger asChild>
                <Button>
                  <Plus className="mr-1.5 h-4 w-4" />
                  New Text Template
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
                      <Label>Category</Label>
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
                      {errors.message && (
                        <p className="text-sm text-destructive">{errors.message}</p>
                      )}
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
        </div>

        {/* Tab Switcher */}
        <div className="flex gap-2 border-b">
          <button
            className={`pb-2 text-sm font-medium transition-colors ${
              activeTab === 'text'
                ? 'border-b-2 border-primary text-primary'
                : 'text-muted-foreground'
            }`}
            onClick={() => setActiveTab('text')}
          >
            Text Templates ({templates.total})
          </button>
          <button
            className={`pb-2 text-sm font-medium transition-colors ${
              activeTab === 'rich'
                ? 'border-b-2 border-primary text-primary'
                : 'text-muted-foreground'
            }`}
            onClick={() => setActiveTab('rich')}
          >
            Rich Media Templates ({stats?.rich_total ?? 0})
          </button>
        </div>

        {/* Text Templates Tab */}
        {activeTab === 'text' && (
          <>
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
                      <Plus className="mr-1.5 h-4 w-4" />
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
          </>
        )}

        {/* Rich Media Templates Tab */}
        {activeTab === 'rich' && (
          <div className="space-y-4">
            {/* Stats Cards */}
            {stats && (
              <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div className="rounded-lg border p-3 text-center">
                  <p className="text-2xl font-bold">{stats.total}</p>
                  <p className="text-xs text-muted-foreground">Total Templates</p>
                </div>
                <div className="rounded-lg border p-3 text-center">
                  <p className="text-2xl font-bold text-muted-foreground">{stats.text}</p>
                  <p className="text-xs text-muted-foreground">Text Only</p>
                </div>
                <div className="rounded-lg border p-3 text-center">
                  <p className="text-2xl font-bold text-primary">{stats.button}</p>
                  <p className="text-xs text-muted-foreground">Buttons</p>
                </div>
                <div className="rounded-lg border p-3 text-center">
                  <p className="text-2xl font-bold text-info">{stats.card}</p>
                  <p className="text-xs text-muted-foreground">Cards</p>
                </div>
                <div className="rounded-lg border p-3 text-center">
                  <p className="text-2xl font-bold text-success">{stats.carousel}</p>
                  <p className="text-xs text-muted-foreground">Carousels</p>
                </div>
              </div>
            )}

            {richMediaList.length === 0 ? (
              <Card>
                <CardContent className="py-12">
                  <div className="text-center">
                    <LayoutGrid className="mx-auto mb-4 h-12 w-12 text-muted-foreground" />
                    <h3 className="text-lg font-medium">No rich media templates yet</h3>
                    <p className="mt-1 mb-4 text-muted-foreground">
                      Create interactive buttons, product cards, or carousels for Facebook
                      Messenger.
                    </p>
                    <Button onClick={() => setRichMediaOpen(true)}>
                      <Plus className="mr-1.5 h-4 w-4" />
                      Create Rich Media Template
                    </Button>
                  </div>
                </CardContent>
              </Card>
            ) : (
              <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {richMediaList.map((template) => (
                  <Card key={template.id}>
                    <CardHeader className="pb-3">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <CardTitle className="text-base">{template.title}</CardTitle>
                          <div className="mt-1 flex flex-wrap gap-1">
                            <Badge variant="outline" className="flex items-center gap-1">
                              {mediaTypeIcon(template.media_type)}
                              <span className="ml-0.5">{template.media_type_label}</span>
                            </Badge>
                            {template.category && (
                              <Badge variant="secondary" className="capitalize">
                                {template.category.replace('_', ' ')}
                              </Badge>
                            )}
                          </div>
                        </div>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8 text-destructive"
                          onClick={() => handleDeleteRichMedia(template.id, template.title)}
                          title="Delete template"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      <div className="rounded-lg bg-muted p-3">
                        <p className="text-sm">{template.content}</p>
                      </div>

                      {/* Media-specific preview */}
                      {template.media_type === 'button' && template.media_config?.buttons && (
                        <div className="space-y-1">
                          {template.media_config.buttons.map((btn: ButtonItem, i: number) => (
                            <div
                              key={i}
                              className="flex items-center justify-between rounded-md border px-2 py-1 text-xs"
                            >
                              <span className="font-medium">{btn.title}</span>
                              <span className="text-muted-foreground">{btn.type}</span>
                            </div>
                          ))}
                        </div>
                      )}

                      {template.media_type === 'card' && template.media_config && (
                        <div className="rounded-md border p-2">
                          {template.media_config.image_url && (
                            <div className="mb-2 h-24 rounded bg-muted" />
                          )}
                          <p className="text-sm font-medium">{template.media_config.title}</p>
                          <p className="text-xs text-muted-foreground">
                            {template.media_config.subtitle}
                          </p>
                          {template.media_config.buttons?.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                              {template.media_config.buttons.map((btn: ButtonItem, i: number) => (
                                <Badge key={i} variant="outline" className="text-xs">
                                  {btn.title}
                                </Badge>
                              ))}
                            </div>
                          )}
                        </div>
                      )}

                      {template.media_type === 'carousel' && template.media_config?.cards && (
                        <div className="flex gap-2 overflow-x-auto pb-1">
                          {template.media_config.cards.map((card: CardItem, i: number) => (
                            <div key={i} className="min-w-[140px] rounded-md border p-2">
                              {card.image_url && <div className="mb-1 h-16 rounded bg-muted" />}
                              <p className="truncate text-xs font-medium">{card.title}</p>
                              <p className="truncate text-xs text-muted-foreground">
                                {card.subtitle}
                              </p>
                              {card.buttons && card.buttons.length > 0 && (
                                <Badge variant="outline" className="mt-1 text-xs">
                                  {card.buttons.length} button(s)
                                </Badge>
                              )}
                            </div>
                          ))}
                        </div>
                      )}

                      <p className="text-xs text-muted-foreground">
                        {template.creator ? `Created by ${template.creator} ` : ''}
                        {formatDistanceToNow(new Date(template.updated_at), { addSuffix: true })}
                      </p>
                    </CardContent>
                  </Card>
                ))}
              </div>
            )}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
