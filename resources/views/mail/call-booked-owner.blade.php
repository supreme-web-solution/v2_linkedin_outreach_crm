<x-mail::message>
# New call booked

Hi {{ $ownerName }},

**{{ $prospectName }}** just booked a call with you through your booking link.

**When:** {{ $scheduledLabel }}

**Prospect email:** {{ $prospectEmail }}

@if($prospectHeadline !== '')
**Headline:** {{ $prospectHeadline }}
@endif

The event has been added to your connected calendar. Google does not email organizers for events you create — this email is your confirmation.

<x-mail::button :url="$callUrl">
View call in Call Manager
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
