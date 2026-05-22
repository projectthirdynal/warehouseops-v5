@component('mail::message')
# Password Reset Request

You are receiving this email because we received a password reset request for your account.

@if (! $request->has('from_admin'))
This is a self-service password reset initiated from the login page.
@else
This password reset was initiated by an administrator.
@endif

**User:** {{ $user->name }}  
**Email:** {{ $user->email }}

@component('mail::button')
['Reset Password']({{ $url }})
@endcomponent

This password reset link will expire in {{ config('auth.passwords.'.config('auth.defaults.passwords').'.expire') }} minutes.

If you did not request a password reset, no further action is required. Your password will remain unchanged.

---

Regards,  
{{ config('app.name') }} Team
@endcomponent
