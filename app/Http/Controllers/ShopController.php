<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ShopController extends Controller
{
    public function index()
    {
        return Inertia::render('Shop/Index', [
            'stats' => [
                'connected_pages' => 0,
                'open_conversations' => 0,
                'orders_today' => 0,
                'for_encoding' => 0,
            ],
            'modules' => [
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
                    'status' => 'Foundation',
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
                'Create Shop database migrations for customers, identities, conversations, messages, orders, and exports.',
                'Build manual order creation before Facebook ingestion.',
                'Add Meta app configuration and encrypted Page token storage.',
                'Implement webhook verification and raw event capture.',
            ],
        ]);
    }
}
