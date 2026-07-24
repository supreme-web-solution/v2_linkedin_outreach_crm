<x-mail::message>
# You're invited!

**{{ $inviterName }}** has invited you to collaborate on **{{ $organizationName }}** as **{{ $role }}**.

@if($expiresAt)
This invitation expires on {{ $expiresAt }}.
@endif

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

If you don't have an account yet, register with the same email address first, then click the button above.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
