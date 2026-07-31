<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Courier\Services\MockCourierService;
use App\Models\Waybill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MockCourierController extends Controller
{
    public function index(): \Inertia\Response
    {
        $service = app(MockCourierService::class);
        $orders = $service->getAllOrders();

        return Inertia::render('Waybills/MockCourierApi', [
            'orders'     => array_values($orders),
            'totalOrders' => count($orders),
        ]);
    }

    public function apiOrders(): JsonResponse
    {
        $service = app(MockCourierService::class);

        return response()->json([
            'orders' => array_values($service->getAllOrders()),
            'total'  => count($service->getAllOrders()),
        ]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'waybill_id'       => 'nullable|integer|exists:waybills,id',
            'receiver_name'    => 'required|string|max:255',
            'receiver_phone'   => 'required|string|max:50',
            'receiver_address' => 'required|string|max:500',
            'receiver_city'    => 'nullable|string|max:100',
            'item_name'        => 'nullable|string|max:255',
            'item_qty'         => 'nullable|integer|min:1',
            'cod_amount'       => 'nullable|numeric|min:0',
            'weight'           => 'nullable|numeric|min:0.1',
        ]);

        $service = app(MockCourierService::class);

        $dto = new \App\Domain\Courier\DTOs\CreateOrderDTO(
            senderName:      config('app.name', 'WarehouseOps'),
            senderPhone:     '',
            senderAddress:   '',
            senderCity:      '',
            senderProvince:  '',
            receiverName:    $validated['receiver_name'],
            receiverPhone:   $validated['receiver_phone'],
            receiverAddress: $validated['receiver_address'],
            receiverCity:    $validated['receiver_city'] ?? '',
            receiverProvince: '',
            receiverBarangay: '',
            postalCode:      null,
            itemName:        $validated['item_name'] ?? 'Package',
            itemQty:         $validated['item_qty'] ?? 1,
            itemValue:       0,
            codAmount:       (float) ($validated['cod_amount'] ?? 0),
            weight:          (float) ($validated['weight'] ?? 0.5),
            waybillId:       $validated['waybill_id'] ?? null,
        );

        $result = $service->createOrder($dto);

        return response()->json([
            'success'        => $result->success,
            'trackingNumber' => $result->trackingNumber,
            'sortCode'       => $result->sortCode,
            'error'          => $result->errorMessage,
        ], $result->success ? 201 : 422);
    }

    public function createFromWaybill(Waybill $waybill): JsonResponse
    {
        $service = app(MockCourierService::class);
        $dto = \App\Domain\Courier\DTOs\CreateOrderDTO::fromWaybill($waybill);
        $result = $service->createOrder($dto);

        return response()->json([
            'success'        => $result->success,
            'trackingNumber' => $result->trackingNumber,
            'sortCode'       => $result->sortCode,
            'error'          => $result->errorMessage,
        ], $result->success ? 201 : 422);
    }

    public function trackOrder(string $trackingNumber): JsonResponse
    {
        $service = app(MockCourierService::class);
        $order = $service->getOrder($trackingNumber);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($order);
    }

    public function advanceStatus(string $trackingNumber): JsonResponse
    {
        $service = app(MockCourierService::class);
        $order = $service->advanceStatus($trackingNumber);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($order);
    }

    public function setStatus(Request $request, string $trackingNumber): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:PENDING,DISPATCHED,PICKED_UP,IN_TRANSIT,ARRIVED_HUB,OUT_FOR_DELIVERY,DELIVERY_FAILED,DELIVERED,RETURNING,RETURNED,CANCELLED',
        ]);

        $service = app(MockCourierService::class);
        $order = $service->setStatus($trackingNumber, $validated['status']);

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($order);
    }

    public function cancelOrder(string $trackingNumber): JsonResponse
    {
        $service = app(MockCourierService::class);
        $success = $service->cancelOrder($trackingNumber);

        if (!$success) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Order cancelled']);
    }

    public function simulateWebhook(string $trackingNumber): JsonResponse
    {
        $service = app(MockCourierService::class);
        $payload = $service->generateWebhookPayload($trackingNumber);

        if (!$payload) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json([
            'success'   => true,
            'payload'   => $payload,
            'webhookUrl' => url('/api/courier/webhook/MOCK'),
            'message'   => 'Webhook payload generated. Send this to the webhook endpoint to simulate a courier callback.',
        ]);
    }

    public function resetAll(): JsonResponse
    {
        $service = app(MockCourierService::class);
        $service->reset();

        return response()->json(['success' => true, 'message' => 'All mock orders cleared']);
    }

    public function resetOrder(string $trackingNumber): JsonResponse
    {
        $service = app(MockCourierService::class);
        $success = $service->resetOrder($trackingNumber);

        if (!$success) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Mock order deleted']);
    }
}
