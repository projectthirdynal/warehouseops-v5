<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        return Inertia::render('Settings/Index', [
            'settings' => [
                'max_active_leads' => 10,
                'recycle_attempts' => 3,
                'callback_expiry_hours' => 24,
            ],
            'user' => $currentUser->only([
                'id', 'name', 'email', 'phone', 'role',
                'theme', 'language', 'timezone',
            ]),
            'system_settings' => [
                'company_name' => SiteSetting::get('company_name', 'TECS Warehouse Operations'),
                'timezone' => SiteSetting::get('timezone', 'Asia/Manila'),
                'date_format' => SiteSetting::get('date_format', 'MM/DD/YYYY'),
                'time_format' => SiteSetting::get('time_format', '12 Hour (AM/PM)'),
                'currency' => SiteSetting::get('currency', 'PHP - Philippine Peso'),
                'language' => SiteSetting::get('language', 'en'),
            ],
            'integrations' => [
                [
                    'name'        => 'Google Workspace',
                    'icon'        => 'google',
                    'status'      => SiteSetting::get('integration_google_workspace', 'connected'),
                    'description' => 'Email and calendar sync',
                    'key'         => 'google_workspace',
                    'settings'    => [
                        'client_id'     => SiteSetting::get('integration_google_workspace_client_id', ''),
                        'client_secret' => SiteSetting::get('integration_google_workspace_client_secret', ''),
                        'redirect_uri'  => SiteSetting::get('integration_google_workspace_redirect_uri', ''),
                    ],
                ],
                [
                    'name'        => 'Slack',
                    'icon'        => 'slack',
                    'status'      => SiteSetting::get('integration_slack', 'connected'),
                    'description' => 'Team notifications',
                    'key'         => 'slack',
                    'settings'    => [
                        'webhook_url' => SiteSetting::get('integration_slack_webhook_url', ''),
                        'channel'     => SiteSetting::get('integration_slack_channel', ''),
                    ],
                ],
                [
                    'name'        => 'Microsoft 365',
                    'icon'        => 'microsoft',
                    'status'      => SiteSetting::get('integration_microsoft_365', 'disconnected'),
                    'description' => 'Office integration',
                    'key'         => 'microsoft_365',
                    'settings'    => [
                        'tenant_id'     => SiteSetting::get('integration_microsoft_365_tenant_id', ''),
                        'client_id'     => SiteSetting::get('integration_microsoft_365_client_id', ''),
                        'client_secret' => SiteSetting::get('integration_microsoft_365_client_secret', ''),
                    ],
                ],
                [
                    'name'        => 'Webhook',
                    'icon'        => 'webhook',
                    'status'      => SiteSetting::get('integration_webhook', 'connected'),
                    'description' => 'Custom event notifications',
                    'key'         => 'webhook',
                    'settings'    => [
                        'endpoint_url' => SiteSetting::get('integration_webhook_endpoint_url', ''),
                        'secret_token' => SiteSetting::get('integration_webhook_secret_token', ''),
                    ],
                ],
            ],
            'email_settings' => [
                'mailer'        => SiteSetting::get('mail_mailer', 'smtp'),
                'host'          => SiteSetting::get('mail_host', ''),
                'port'          => SiteSetting::get('mail_port', '587'),
                'encryption'    => SiteSetting::get('mail_encryption', 'tls'),
                'username'      => SiteSetting::get('mail_username', ''),
                'password'      => SiteSetting::get('mail_password') ? '••••••••' : '',
                'from_address'  => SiteSetting::get('mail_from_address', ''),
                'from_name'     => SiteSetting::get('mail_from_name', SiteSetting::get('company_name', 'WarehouseOps')),
                'is_configured' => (bool) SiteSetting::get('mail_host'),
            ],
            'printer_settings' => [
                'enabled'         => SiteSetting::get('printer_enabled', '0') === '1',
                'type'            => SiteSetting::get('printer_type', 'network'),
                'ip_address'      => SiteSetting::get('printer_ip', ''),
                'port'            => SiteSetting::get('printer_port', '9100'),
                'dpi'             => SiteSetting::get('printer_dpi', '203'),
                'label_width_mm'  => SiteSetting::get('printer_label_width', '100'),
                'label_height_mm' => SiteSetting::get('printer_label_height', '50'),
                'copies'          => SiteSetting::get('printer_copies', '1'),
                'printer_name'    => SiteSetting::get('printer_name', ''),
            ],
            'scanner_settings' => [
                'enabled'          => SiteSetting::get('scanner_enabled', '1') === '1',
                'mode'             => SiteSetting::get('scanner_default_mode', 'validate'),
                'sound_enabled'    => SiteSetting::get('scanner_sound', '1') === '1',
                'auto_submit'      => SiteSetting::get('scanner_auto_submit', '1') === '1',
                'beep_on_success'  => SiteSetting::get('scanner_beep_success', '1') === '1',
                'beep_on_error'    => SiteSetting::get('scanner_beep_error', '1') === '1',
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->update($validated);
        ActivityLog::log('update_profile', $request->user(), 'user', null, ['user_id' => $request->user()->id]);

        return redirect()->back(303)->with('success', 'Profile updated.');
    }

    public function updateAppearance(Request $request)
    {
        $validated = $request->validate([
            'theme'    => ['required', 'in:light,dark,system'],
            'language' => ['required', 'in:en,tl'],
            'timezone' => ['required', 'string', 'max:50'],
        ]);

        $request->user()->update($validated);

        return redirect()->back(303)->with('success', 'Appearance saved.');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);
        ActivityLog::log('update_password', $request->user(), 'user', null, ['user_id' => $request->user()->id]);

        return redirect()->back(303)->with('success', 'Password updated.');
    }

    /* ─── System Settings ─── */

    public function updateSystemSettings(Request $request)
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'timezone'     => ['required', 'string', 'max:50'],
            'date_format'  => ['required', 'in:MM/DD/YYYY,DD/MM/YYYY,YYYY-MM-DD'],
            'time_format'  => ['required', 'in:12,24'],
            'currency'     => ['required', 'string', 'max:50'],
            'language'     => ['required', 'in:en,tl'],
        ]);

        foreach ($validated as $key => $value) {
            SiteSetting::set($key, $value);
        }

        ActivityLog::log('update_system_settings', $request->user(), 'system', null, $validated);

        return redirect()->back(303)->with('success', 'System settings saved.');
    }

    /* ─── Email / SMTP Settings ─── */

    public function updateEmailSettings(Request $request)
    {
        $request->validate([
            'mailer'       => ['required', 'in:smtp,log,mailpit'],
            'host'         => ['nullable', 'string', 'max:255'],
            'port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'encryption'   => ['nullable', 'in:tls,ssl,none'],
            'username'     => ['nullable', 'string', 'max:255'],
            'password'     => ['nullable', 'string', 'max:500'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name'    => ['required', 'string', 'max:255'],
        ]);

        SiteSetting::set('mail_mailer',       $request->mailer);
        SiteSetting::set('mail_host',         $request->host ?? '');
        SiteSetting::set('mail_port',         (string) ($request->port ?? 587));
        SiteSetting::set('mail_encryption',   $request->encryption ?? 'tls');
        SiteSetting::set('mail_username',     $request->username ?? '');
        SiteSetting::set('mail_from_address', $request->from_address);
        SiteSetting::set('mail_from_name',    $request->from_name);

        if ($request->filled('password') && $request->password !== '••••••••') {
            SiteSetting::set('mail_password', Crypt::encryptString($request->password));
        }

        ActivityLog::log('update_email_settings', $request->user(), 'system', null, ['mailer' => $request->mailer, 'host' => $request->host]);

        return redirect()->back(303)->with('success', 'Email settings saved.');
    }

    public function testEmail(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'email'],
        ]);

        $host       = SiteSetting::get('mail_host', '');
        $mailer     = SiteSetting::get('mail_mailer', 'log');
        $username   = SiteSetting::get('mail_username', '');
        $password   = SiteSetting::get('mail_password', '');
        $port       = (int) SiteSetting::get('mail_port', 587);
        $encryption = SiteSetting::get('mail_encryption', 'tls');
        $fromAddr   = SiteSetting::get('mail_from_address', 'noreply@example.com');
        $fromName   = SiteSetting::get('mail_from_name', 'WarehouseOps');

        if (empty($host) && $mailer === 'smtp') {
            return response()->json(['ok' => false, 'message' => 'SMTP not configured yet. Add a host first.'], 422);
        }

        try {
            $decryptedPassword = '';
            if ($password) {
                try { $decryptedPassword = Crypt::decryptString($password); } catch (\Throwable) { $decryptedPassword = $password; }
            }

            config([
                'mail.default'                    => $mailer,
                'mail.mailers.smtp.host'          => $host,
                'mail.mailers.smtp.port'          => $port,
                'mail.mailers.smtp.encryption'    => $encryption === 'none' ? null : $encryption,
                'mail.mailers.smtp.username'      => $username,
                'mail.mailers.smtp.password'      => $decryptedPassword,
                'mail.from.address'               => $fromAddr,
                'mail.from.name'                  => $fromName,
            ]);

            // Purge the cached mailer so Laravel re-resolves with the new config above
            Mail::purge($mailer);
            Mail::purge('smtp');

            Mail::raw(
                "This is a test email from WarehouseOps.\n\nIf you received this, your SMTP configuration is working correctly.\n\nSent at: " . now()->toDateTimeString(),
                fn ($m) => $m->to($request->to)->subject('WarehouseOps — SMTP Test Email')
            );

            return response()->json(['ok' => true, 'message' => "Test email sent to {$request->to}."]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /* ─── Label Printer Settings ─── */

    public function updateLabelPrinter(Request $request)
    {
        $request->validate([
            'enabled'         => ['boolean'],
            'type'            => ['required', 'in:network,usb,windows'],
            'ip_address'      => ['nullable', 'string', 'max:255'],
            'port'            => ['nullable', 'integer', 'min:1', 'max:65535'],
            'dpi'             => ['required', 'in:203,300,600'],
            'label_width_mm'  => ['required', 'integer', 'min:20', 'max:300'],
            'label_height_mm' => ['required', 'integer', 'min:10', 'max:300'],
            'copies'          => ['required', 'integer', 'min:1', 'max:10'],
            'printer_name'    => ['nullable', 'string', 'max:255'],
        ]);

        SiteSetting::set('printer_enabled',      $request->enabled ? '1' : '0');
        SiteSetting::set('printer_type',         $request->type);
        SiteSetting::set('printer_ip',           $request->ip_address ?? '');
        SiteSetting::set('printer_port',         (string) ($request->port ?? 9100));
        SiteSetting::set('printer_dpi',          (string) $request->dpi);
        SiteSetting::set('printer_label_width',  (string) $request->label_width_mm);
        SiteSetting::set('printer_label_height', (string) $request->label_height_mm);
        SiteSetting::set('printer_copies',       (string) $request->copies);
        SiteSetting::set('printer_name',         $request->printer_name ?? '');

        return redirect()->back(303)->with('success', 'Label printer settings saved.');
    }

    /* ─── Scanner Settings ─── */

    public function updateScannerSettings(Request $request)
    {
        $request->validate([
            'enabled'         => ['boolean'],
            'mode'            => ['required', 'in:validate,dispatch,receive_return'],
            'sound_enabled'   => ['boolean'],
            'auto_submit'     => ['boolean'],
            'beep_on_success' => ['boolean'],
            'beep_on_error'   => ['boolean'],
        ]);

        SiteSetting::set('scanner_enabled',      $request->enabled ? '1' : '0');
        SiteSetting::set('scanner_default_mode', $request->mode);
        SiteSetting::set('scanner_sound',        $request->sound_enabled ? '1' : '0');
        SiteSetting::set('scanner_auto_submit',  $request->auto_submit ? '1' : '0');
        SiteSetting::set('scanner_beep_success', $request->beep_on_success ? '1' : '0');
        SiteSetting::set('scanner_beep_error',   $request->beep_on_error ? '1' : '0');

        return redirect()->back(303)->with('success', 'Scanner settings saved.');
    }

    /* ─── Integrations ─── */

    public function toggleIntegration(Request $request)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'in:google_workspace,slack,microsoft_365,webhook'],
        ]);

        $settingKey = "integration_{$validated['key']}";
        $current = SiteSetting::get($settingKey, 'disconnected');
        $newStatus = $current === 'connected' ? 'disconnected' : 'connected';

        SiteSetting::set($settingKey, $newStatus);

        ActivityLog::log('toggle_integration', $request->user(), 'system', null, [
            'integration' => $validated['key'],
            'status' => $newStatus,
        ]);

        $action = $newStatus === 'connected' ? 'connected' : 'disconnected';

        return redirect()->back(303)->with('success', "Integration {$action} successfully.");
    }

    public function updateIntegrationSettings(Request $request)
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'in:google_workspace,slack,microsoft_365,webhook'],
            'settings' => ['required', 'array'],
        ]);

        foreach ($validated['settings'] as $field => $value) {
            SiteSetting::set("integration_{$validated['key']}_{$field}", $value);
        }

        ActivityLog::log('update_integration_settings', $request->user(), 'system', null, [
            'integration' => $validated['key'],
            'fields' => array_keys($validated['settings']),
        ]);

        return redirect()->back(303)->with('success', 'Integration settings saved.');
    }
}
