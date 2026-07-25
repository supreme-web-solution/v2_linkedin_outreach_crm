<x-mail::message>
# Call reminder

Hi {{ $prospectName }},

@if($scheduledLabel)
Your call with **{{ $hostName }}** is coming up on **{{ $scheduledLabel }}**.
@else
Your call with **{{ $hostName }}** is coming up soon.
@endif

{{ $message }}

If you added the calendar invite to your calendar, you may also receive reminders from Google or Outlook.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
