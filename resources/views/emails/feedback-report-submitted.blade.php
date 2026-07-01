@component('mail::message')
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
{{ config('app.name') }}
@endcomponent
