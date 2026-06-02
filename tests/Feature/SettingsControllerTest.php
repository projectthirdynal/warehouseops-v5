<?php

declare(strict_types=1);

use App\Models\User;

test('savePrinterSettings requires authentication', function () {
    $response = $this->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(401);
});

test('savePrinterSettings is blocked for warehouse role', function () {
    $user = User::factory()->create(['role' => 'warehouse']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(403);
});

test('savePrinterSettings is blocked for accounting role', function () {
    $user = User::factory()->create(['role' => 'accounting']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(403);
});

test('savePrinterSettings is blocked for agent role', function () {
    $user = User::factory()->create(['role' => 'agent']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(403);
});

test('savePrinterSettings is allowed for supervisor', function () {
    $user = User::factory()->create(['role' => 'supervisor']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(303);
    $response->assertSessionHas('success', 'Printer settings saved.');
});

test('savePrinterSettings is allowed for admin', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(303);
});

test('savePrinterSettings is allowed for superadmin', function () {
    $user = User::factory()->create(['role' => 'superadmin']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(303);
});

test('savePrinterSettings validates required fields', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $response = $this->actingAs($user)->post('/settings/printer', []);
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['printer_model', 'connection_type', 'label_width_mm', 'label_height_mm', 'dpi', 'barcode_format']);
});

test('savePrinterSettings validates connection_type enum', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'invalid',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['connection_type']);
});

test('savePrinterSettings validates label dimensions bounds', function () {
    $user = User::factory()->create(['role' => 'admin']);
    
    // Width too small
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 5,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['label_width_mm']);

    // Width too large
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 200,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['label_width_mm']);
});

test('savePrinterSettings validates DPI enum', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 150, // Invalid DPI
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['dpi']);
});

test('savePrinterSettings validates barcode_format enum', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $response = $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'invalid',
        'is_active' => true,
    ]);
    $response->assertStatus(302);
    $response->assertSessionHasErrors(['barcode_format']);
});

test('savePrinterSettings stores settings in database', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user)->post('/settings/printer', [
        'printer_model' => 'Zebra ZD420',
        'connection_type' => 'usb',
        'label_width_mm' => 50,
        'label_height_mm' => 25,
        'dpi' => 203,
        'barcode_format' => 'code128',
        'is_active' => true,
    ]);

    $this->assertDatabaseHas('system_settings', [
        'key' => 'printer_model',
        'value' => 'Zebra ZD420',
        'group' => 'printer',
        'type' => 'string',
    ]);

    $this->assertDatabaseHas('system_settings', [
        'key' => 'label_width_mm',
        'value' => '50',
        'group' => 'printer',
        'type' => 'int',
    ]);

    $this->assertDatabaseHas('system_settings', [
        'key' => 'is_active',
        'value' => '1',
        'group' => 'printer',
        'type' => 'bool',
    ]);
});
