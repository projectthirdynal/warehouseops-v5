<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ShopController extends Controller
{
    public function index()
    {
        return Inertia::render('Shop/Index', [
            'stats' => $this->stats(),
            'modules' => [
                [
                    'name' => 'POS Core Schema',
                    'status' => 'Foundation',
                    'description' => 'Database foundation for Facebook identities, conversations, messages, order items, remarks, address mapping, and courier exports.',
                    'items' => ['Customer identities', 'Conversations', 'Messages', 'Export batches'],
                ],
                [
                    'name' => 'Facebook Connector',
                    'status' => 'Planned',
                    'description' => 'Connect Meta accounts, list Pages, store encrypted Page tokens, and subscribe webhooks.',
                    'items' => ['Login with Facebook', 'Fetch Pages', 'Page token vault', 'Webhook subscription'],
                ],
                [
                    'name' => 'Multi-page Inbox',
                    'status' => 'Planned',
                    'description' => 'Central inbox for Messenger messages and Page comments across connected selling Pages.',
                    'items' => ['Page filters', 'Unread queue', 'Agent assignment', 'Conversation history'],
                ],
                [
                    'name' => 'Order Desk',
                    'status' => 'Schema Ready',
                    'description' => 'Create structured orders from conversations with products, COD amount, remarks, and customer details.',
                    'items' => ['Customer lookup', 'Same-address shortcut', 'Order items', 'Status workflow'],
                ],
                [
                    'name' => 'Encoder & Export',
                    'status' => 'Planned',
                    'description' => 'Validate addresses, map regions, and export courier-ready sheets for J&T, Flash, and other COD couriers.',
                    'items' => ['Address confidence', 'Encoder queue', 'Courier batches', 'CSV/XLSX export'],
                ],
            ],
            'workflow' => [
                'Connect Pages',
                'Receive Messages',
                'Detect Phone',
                'Match Customer',
                'Create Order',
                'Validate Address',
                'Export Courier File',
            ],
            'next_actions' => [
                'Build manual order creation before Facebook ingestion.',
                'Add phone normalization and customer identity matching services.',
                'Seed Philippine address mapping references for province, city, barangay, and courier zone.',
                'Add Meta app configuration and encrypted Page token storage.',
                'Implement webhook verification and raw event capture.',
            ],
        ]);
    }

    private function stats(): array
    {
        return [
            'connected_pages' => $this->countWhenReady('facebook_pages', fn () => DB::table('facebook_pages')
                ->where('connected_status', 'connected')
                ->count()),
            'open_conversations' => $this->countWhenReady('conversations', fn () => DB::table('conversations')
                ->where('status', 'open')
                ->count()),
            'orders_today' => $this->countWhenReady('orders', fn () => DB::table('orders')
                ->whereDate('created_at', today())
                ->count()),
            'for_encoding' => $this->forEncodingCount(),
        ];
    }

    private function forEncodingCount(): int
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'encoded_at')) {
            return 0;
        }

        return (int) DB::table('orders')
            ->whereIn('status', ['CONFIRMED', 'For Encoding', 'for_encoding'])
            ->whereNull('encoded_at')
            ->count();
    }

    private function countWhenReady(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) $callback();
    }
}
