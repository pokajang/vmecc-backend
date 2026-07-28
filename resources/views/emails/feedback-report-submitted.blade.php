@component('mail::message')
<x-mail.preheader>A new feedback report is ready for administrator review.</x-mail.preheader>
<x-mail.category>System feedback</x-mail.category>

# New Feedback Report

A new feedback report was submitted in {{ config('app.name') }}.

**Reporter:** {{ $reporter?->name ?: 'Unknown user' }}@if($reporter?->email) ({{ $reporter->email }})@endif  
**Submitted at:** {{ optional($report->created_at)->toDayDateTimeString() }}  
**Page title:** {{ $pageContext['title'] ?? 'Not provided' }}  
**Page path:** {{ $pageContext['path'] ?? 'Not provided' }}

@if(!empty($pageContext['search']))
**Page query:** {{ $pageContext['search'] }}
@endif

**Message**

{{ $report->message }}

@component('mail::button', ['url' => $adminUrl])
Review Feedback Reports
@endcomponent

Thanks,<br>
{{ config('mail.branding.product_name', config('app.name')) }}
@endcomponent
