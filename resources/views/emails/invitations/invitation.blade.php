<x-mail::message>
<h1 style="margin: 0 0 16px; color: #3d4852; font-size: 24px; line-height: 1.3;">You're Invited!</h1>

@if ($guestName)
<p style="margin: 0 0 16px;">Dear {{ $guestName }},</p>
@endif

<p style="margin: 0 0 16px;">We would be delighted to have you join us for <strong>{{ $eventName }}</strong>.</p>

<p style="margin: 0 0 16px;">It would mean so much to share this special occasion with you. We warmly welcome you to celebrate this memorable moment with us.</p>

@if ($hasDetails)
<h2 style="margin: 8px 0 12px; color: #3d4852; font-size: 16px;">Event Details</h2>

@if ($date)
<p style="margin: 0 0 8px;"><strong>Date:</strong> {{ $date }}</p>
@endif

@if ($venue)
<p style="margin: 0 0 8px;"><strong>Venue:</strong> {{ $venue }}</p>
@endif
@endif

<h2 style="margin: 24px 0 12px; color: #3d4852; font-size: 16px;">Your invitation is ready</h2>

<p style="margin: 0 0 16px;">Please open your personal invitation to view the details and let us know whether you'll be joining us.</p>

<x-mail::button :url="$invitationUrl">
View Invitation &amp; RSVP
</x-mail::button>

<p style="margin: 0 0 8px;">If the button does not work, copy and paste this link into your browser:</p>

<p style="margin: 0 0 16px;"><a href="{{ $invitationUrl }}">{{ $invitationUrl }}</a></p>

<p style="margin: 0 0 16px;">We look forward to celebrating with you!</p>

<p style="margin: 0;">Warm regards,<br>
{{ $senderName }}</p>
</x-mail::message>
