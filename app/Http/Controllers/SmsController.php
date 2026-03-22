<?php

namespace App\Http\Controllers;

use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Models\SmsSequence;
use App\Models\SmsSequenceStep;
use App\Models\SmsTemplate;
use App\Models\Waybill;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SmsController extends Controller
{
    public function __construct(private SmsService $smsService)
    {
    }

    public function index()
    {
        $campaigns = SmsCampaign::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $stats = [
            'total_sent' => SmsLog::where('status', 'sent')->count(),
            'total_failed' => SmsLog::where('status', 'failed')->count(),
            'campaigns_active' => SmsCampaign::whereIn('status', ['sending', 'scheduled'])->count(),
            'sequences_active' => SmsSequence::where('is_active', true)->count(),
        ];

        $recentLogs = SmsLog::orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return Inertia::render('Sms/Index', [
            'campaigns' => $campaigns,
            'stats' => $stats,
            'recentLogs' => $recentLogs,
        ]);
    }

    public function create()
    {
        $templates = SmsTemplate::orderBy('name')->get();

        $audienceOptions = [
            ['value' => 'all_customers', 'label' => 'All Customers'],
            ['value' => 'delivered', 'label' => 'Delivered Orders'],
            ['value' => 'pending', 'label' => 'Pending Orders'],
            ['value' => 'returned', 'label' => 'Returned Orders'],
            ['value' => 'custom', 'label' => 'Custom Filter'],
        ];

        $variables = $this->smsService->getAvailableVariables();

        return Inertia::render('Sms/Create', [
            'templates' => $templates,
            'audienceOptions' => $audienceOptions,
            'variables' => $variables,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'type' => 'required|in:broadcast,sequence,reminder',
            'target_audience' => 'required|string',
            'filters' => 'nullable|array',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $campaign = SmsCampaign::create([
            'name' => $request->name,
            'message' => $request->message,
            'type' => $request->type,
            'target_audience' => $request->target_audience,
            'filters' => $request->filters,
            'status' => $request->scheduled_at ? 'scheduled' : 'draft',
            'scheduled_at' => $request->scheduled_at,
            'created_by' => $request->user()->id,
        ]);

        // Calculate recipients based on audience
        $recipientCount = $this->getRecipientCount($campaign);
        $campaign->update(['total_recipients' => $recipientCount]);

        return redirect()->route('sms.show', $campaign)
            ->with('success', 'Campaign created successfully');
    }

    public function show(SmsCampaign $campaign)
    {
        $campaign->load('creator');

        $logs = SmsLog::where('campaign_id', $campaign->id)
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return Inertia::render('Sms/Show', [
            'campaign' => $campaign,
            'logs' => $logs,
        ]);
    }

    public function send(SmsCampaign $campaign)
    {
        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return back()->with('error', 'Campaign cannot be sent in current status');
        }

        $recipients = $this->getRecipients($campaign);

        if (empty($recipients)) {
            return back()->with('error', 'No recipients found for this campaign');
        }

        $campaign->markAsStarted();

        $result = $this->smsService->sendBulk($recipients, $campaign->message, $campaign->id);

        $campaign->update([
            'sent_count' => $result['sent'],
            'failed_count' => $result['failed'],
        ]);

        if ($result['failed'] === 0) {
            $campaign->markAsCompleted();
            return back()->with('success', "Campaign sent successfully! {$result['sent']} messages delivered.");
        } elseif ($result['sent'] > 0) {
            $campaign->markAsCompleted();
            return back()->with('success', "Campaign completed with {$result['sent']} sent, {$result['failed']} failed.");
        } else {
            $campaign->markAsFailed();
            return back()->with('error', 'Campaign failed to send. Check logs for details.');
        }
    }

    public function preview(Request $request)
    {
        $request->validate([
            'target_audience' => 'required|string',
            'filters' => 'nullable|array',
        ]);

        $campaign = new SmsCampaign([
            'target_audience' => $request->target_audience,
            'filters' => $request->filters,
        ]);

        $count = $this->getRecipientCount($campaign);
        $sample = $this->getRecipients($campaign, 5);

        return response()->json([
            'count' => $count,
            'sample' => $sample,
        ]);
    }

    // Sequences
    public function sequences()
    {
        $sequences = SmsSequence::with(['creator', 'steps'])
            ->withCount('enrollments')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return Inertia::render('Sms/Sequences', [
            'sequences' => $sequences,
        ]);
    }

    public function createSequence()
    {
        $triggerOptions = [
            ['value' => 'waybill_created', 'label' => 'Waybill Created'],
            ['value' => 'waybill_dispatched', 'label' => 'Waybill Dispatched'],
            ['value' => 'waybill_out_for_delivery', 'label' => 'Out for Delivery'],
            ['value' => 'waybill_delivered', 'label' => 'Delivered'],
            ['value' => 'waybill_returned', 'label' => 'Returned'],
            ['value' => 'lead_created', 'label' => 'Lead Created'],
            ['value' => 'lead_sale', 'label' => 'Lead Sale'],
        ];

        $variables = $this->smsService->getAvailableVariables();

        return Inertia::render('Sms/CreateSequence', [
            'triggerOptions' => $triggerOptions,
            'variables' => $variables,
        ]);
    }

    public function storeSequence(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'trigger_event' => 'required|string',
            'steps' => 'required|array|min:1',
            'steps.*.message' => 'required|string|max:1000',
            'steps.*.delay_minutes' => 'required|integer|min:0',
            'steps.*.delay_type' => 'required|in:minutes,hours,days',
        ]);

        $sequence = SmsSequence::create([
            'name' => $request->name,
            'description' => $request->description,
            'trigger_event' => $request->trigger_event,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        foreach ($request->steps as $index => $stepData) {
            SmsSequenceStep::create([
                'sequence_id' => $sequence->id,
                'step_order' => $index + 1,
                'message' => $stepData['message'],
                'delay_minutes' => $stepData['delay_minutes'],
                'delay_type' => $stepData['delay_type'],
                'is_active' => true,
            ]);
        }

        return redirect()->route('sms.sequences')
            ->with('success', 'Sequence created successfully');
    }

    public function toggleSequence(SmsSequence $sequence)
    {
        $sequence->update(['is_active' => !$sequence->is_active]);

        $status = $sequence->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Sequence {$status} successfully");
    }

    // Templates
    public function templates()
    {
        $templates = SmsTemplate::with('creator')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Sms/Templates', [
            'templates' => $templates,
        ]);
    }

    public function storeTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'category' => 'nullable|string|max:50',
        ]);

        // Extract variables from message
        preg_match_all('/\{(\w+)\}/', $request->message, $matches);

        SmsTemplate::create([
            'name' => $request->name,
            'message' => $request->message,
            'category' => $request->category,
            'variables' => $matches[0] ?? [],
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Template created successfully');
    }

    public function destroyTemplate(SmsTemplate $template)
    {
        $template->delete();
        return back()->with('success', 'Template deleted successfully');
    }

    // Quick send for individual waybills
    public function quickSend(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string|max:1000',
            'waybill_id' => 'nullable|exists:waybills,id',
        ]);

        $result = $this->smsService->send(
            $request->phone,
            $request->message,
            ['waybill_id' => $request->waybill_id]
        );

        if ($result['success']) {
            return back()->with('success', 'SMS sent successfully');
        }

        return back()->with('error', 'Failed to send SMS: ' . ($result['error'] ?? 'Unknown error'));
    }

    // Logs
    public function logs(Request $request)
    {
        $query = SmsLog::with(['campaign', 'waybill']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('phone')) {
            $query->where('phone', 'like', "%{$request->phone}%");
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate(50);

        $stats = [
            'total' => SmsLog::count(),
            'sent' => SmsLog::where('status', 'sent')->count(),
            'failed' => SmsLog::where('status', 'failed')->count(),
            'pending' => SmsLog::where('status', 'pending')->count(),
        ];

        return Inertia::render('Sms/Logs', [
            'logs' => $logs,
            'stats' => $stats,
            'filters' => $request->only(['status', 'phone', 'date_from', 'date_to']),
        ]);
    }

    // Helper methods
    private function getRecipientCount(SmsCampaign $campaign): int
    {
        return $this->buildRecipientQuery($campaign)->count();
    }

    private function getRecipients(SmsCampaign $campaign, ?int $limit = null): array
    {
        $query = $this->buildRecipientQuery($campaign);

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->map(function ($waybill) {
            return [
                'phone' => $waybill->receiver_phone,
                'name' => $waybill->receiver_name,
                'waybill_number' => $waybill->waybill_number,
                'waybill_id' => $waybill->id,
                'status' => $waybill->status,
                'cod_amount' => $waybill->cod_amount,
            ];
        })->toArray();
    }

    private function buildRecipientQuery(SmsCampaign $campaign)
    {
        $query = Waybill::query()
            ->whereNotNull('receiver_phone')
            ->where('receiver_phone', '!=', '');

        switch ($campaign->target_audience) {
            case 'delivered':
                $query->where('status', 'DELIVERED');
                break;
            case 'pending':
                $query->whereIn('status', ['PENDING', 'IN_TRANSIT', 'DISPATCHED', 'OUT_FOR_DELIVERY']);
                break;
            case 'returned':
                $query->where('status', 'RETURNED');
                break;
            case 'custom':
                if (!empty($campaign->filters)) {
                    if (!empty($campaign->filters['status'])) {
                        $query->whereIn('status', (array) $campaign->filters['status']);
                    }
                    if (!empty($campaign->filters['date_from'])) {
                        $query->whereDate('created_at', '>=', $campaign->filters['date_from']);
                    }
                    if (!empty($campaign->filters['date_to'])) {
                        $query->whereDate('created_at', '<=', $campaign->filters['date_to']);
                    }
                    if (!empty($campaign->filters['courier'])) {
                        $query->where('courier_provider', $campaign->filters['courier']);
                    }
                }
                break;
        }

        return $query->select(['id', 'waybill_number', 'receiver_phone', 'receiver_name', 'status', 'cod_amount']);
    }
}
