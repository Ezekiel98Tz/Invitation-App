<x-mail::message>
<h1 style="margin: 0 0 16px; color: #3d4852; font-size: 24px; line-height: 1.3;">A Friendly Reminder</h1>

@if ($guestName)
<p style="margin: 0 0 16px;">Dear {{ $guestName }},</p>
@endif

<p style="margin: 0 0 16px;">We're following up on your invitation to <strong>{{ $eventName }}</strong>. We'd still love to have you join us for this special occasion.</p>

<p style="margin: 0 0 16px;">Please take a moment to view your personal invitation and let us know whether you'll be attending.</p>

<x-mail::button :url="$invitationUrl">
View Invitation &amp; RSVP
</x-mail::button>

<p style="margin: 0 0 8px;">If the button does not work, copy and paste this link into your browser:</p>

<p style="margin: 0 0 16px;"><a href="{{ $invitationUrl }}">{{ $invitationUrl }}</a></p>

@if ($hasDetails)
<h2 style="margin: 24px 0 12px; color: #3d4852; font-size: 16px;">Event Details</h2>

@if ($date)
<p style="margin: 0 0 8px;"><strong>Date:</strong> {{ $date }}</p>
@endif

@if ($venue)
<p style="margin: 0 0 8px;"><strong>Venue:</strong> {{ $venue }}</p>
@endif
@endif

<p style="margin: 0 0 16px;">We look forward to hearing from you!</p>

<p style="margin: 0;">Warm regards,<br>
{{ $senderName }}</p>
</x-mail::message>
