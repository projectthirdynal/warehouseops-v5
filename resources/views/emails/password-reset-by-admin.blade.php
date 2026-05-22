@component('mail::message')
# Password Reset by Administrator

Your password has been reset by an administrator.

**User:** {{ $user->name }}  
**Email:** {{ $user->email }}  
**Reset by:** {{ $resetBy->name }}  
**Date:** {{ now()->format('Y-m-d H:i') }}

You will need to use your new password to log in to the system.

@component('mail::button')
['Go to Login']({{ url('/login') }})
@endcomponent

If you have any questions about this password reset, please contact the administrator who initiated this change or your system administrator.

---

Regards,  
{{ config('app.name') }} Team
@endcomponent
