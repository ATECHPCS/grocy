@extends('layout.default')
@php
$grocyAiAssetVersion = '2.6.22';
@endphp
@push('pageStyles')
<link rel="stylesheet" href="{{ $U('/custom/grocy_AI/grocy-ai.css?v=', true) }}{{ $grocyAiAssetVersion }}">
@endpush
@push('pageScripts')
<script src="{{ $U('/custom/grocy_AI/capture-dashboard.js?v=', true) }}{{ $grocyAiAssetVersion }}"></script>
@endpush
@section('title', $__t('Purchase capture'))
@section('content')
<div id="grocyai-capture-dashboard" class="grocy-ai-purchase-flow" data-dashboard-endpoint="{{ $U('/api/grocy-ai/capture/dashboard', true) }}" data-trips-endpoint="{{ $U('/api/grocy-ai/capture/trips', true) }}" data-capture-url="{{ $U('/grocyai/capture', true) }}" data-review-url="{{ $U('/grocyai/capture/review', true) }}" data-label-session="{{ $__t('Session') }}"
 data-label-resume="{{ $__t('Resume') }}"
 data-label-review="{{ $__t('Review') }}"
 data-label-open="{{ $__t('Open') }}"
 data-label-reviewing="{{ $__t('Reviewing') }}"
 data-label-committed="{{ $__t('Completed') }}"
 data-label-completed="{{ $__t('Completed') }}"
 data-label-created="{{ $__t('Created') }}"
 data-label-date-unavailable="{{ $__t('Date unavailable') }}"
 data-label-items="{{ $__t('captured items') }}"
 data-label-receipts="{{ $__t('receipts') }}"
 data-label-open-stats="{{ $__t('Open sessions') }}"
 data-label-review-stats="{{ $__t('Sessions needing review') }}"
 data-label-completed-stats="{{ $__t('Completed purchases · last 30 days') }}"
 data-label-printed-stats="{{ $__t('Printed receipt totals · last 30 days') }}"
 data-label-unavailable="{{ $__t('Unavailable') }}"
 data-label-active-empty="{{ $__t('No active sessions. Choose New session to start.') }}"
 data-label-completed-empty="{{ $__t('No completed purchases yet.') }}"
 data-label-loading="{{ $__t('Loading sessions…') }}"
 data-label-creating="{{ $__t('Creating session…') }}"
 data-label-load-error="{{ $__t('Could not load sessions. Refresh sessions to try again.') }}"
 data-label-create-ambiguous="{{ $__t('Session creation could not be confirmed. Refresh sessions and review any empty session before creating another.') }}"
 data-label-missing-totals="{{ $__t('receipt(s) have no available printed total.') }}"
 data-label-missing-receipts="{{ $__t('completed purchase(s) have no receipts.') }}"
 data-label-total-guidance="{{ $__t('Printed receipt totals cover available receipts only, including ignored items and adjustments. They are not a complete spending total.') }}">
<h2>{{ $__t('Purchase capture') }}</h2><div class="grocy-ai-flow-actions"><button class="btn btn-primary" id="grocyai-dashboard-new" type="button">{{ $__t('New session') }}</button><button class="btn btn-outline-primary" id="grocyai-dashboard-refresh" type="button">{{ $__t('Refresh sessions') }}</button></div>
<p id="grocyai-dashboard-status" role="status" aria-live="polite"></p><div id="grocyai-dashboard-stats" class="grocy-ai-dashboard-stats"></div><p id="grocyai-dashboard-notices"></p>
<section class="grocy-ai-flow-card"><h3>{{ $__t('Continue sessions') }}</h3><ul id="grocyai-dashboard-active" class="grocy-ai-dashboard-list"></ul><button class="btn btn-outline-primary" id="grocyai-dashboard-active-more" type="button" hidden>{{ $__t('Show more active sessions') }}</button></section>
<section class="grocy-ai-flow-card"><h3>{{ $__t('Completed purchases') }}</h3><ul id="grocyai-dashboard-completed" class="grocy-ai-dashboard-list"></ul><button class="btn btn-outline-primary" id="grocyai-dashboard-completed-more" type="button" hidden>{{ $__t('Show more completed purchases') }}</button></section></div>
@stop
