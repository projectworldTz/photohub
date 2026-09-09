@extends('layouts.app')
@section('title','Reports & trends')
@section('content')
<link rel="stylesheet" href="{{ asset('css/reports.css') }}">
<div class="report-page">
<x-page-header title="Reports & trends" subtitle="Track income, understand costs and see how your studio changes over time.">
<div class="d-flex gap-2"><a id="report-csv" class="btn btn-light" href="{{ route('reports.csv',request()->query()) }}">Export CSV</a><a id="report-pdf" class="btn btn-light" href="{{ route('reports.pdf',request()->query()) }}">Export PDF</a></div>
</x-page-header>
<form data-today="{{ now()->toDateString() }}" id="report-filters" action="{{ route('reports.index') }}" method="GET" class="report-filters content-card mb-4">
<div class="d-flex flex-wrap gap-2 mb-3" aria-label="Quick date ranges">
@foreach(['7'=>'Last 7 days','30'=>'Last 30 days','90'=>'Last 90 days','month'=>'This month','year'=>'Year to date'] as $range=>$label)
<button type="button" class="btn btn-sm btn-outline-secondary" data-range="{{ $range }}">{{ $label }}</button>
@endforeach
</div>
<div class="row g-3 align-items-end">
<div class="col-6 col-lg-3"><label for="report-from" class="form-label">From</label><input id="report-from" type="date" name="from" value="{{ $from->toDateString() }}" class="form-control" required></div>
<div class="col-6 col-lg-3"><label for="report-to" class="form-label">To</label><input id="report-to" type="date" name="to" value="{{ $to->toDateString() }}" class="form-control" required></div>
<div class="col-6 col-lg-3"><label for="report-interval" class="form-label">Group by</label><select id="report-interval" class="form-select" name="interval">@foreach(['auto'=>'Automatic','day'=>'Daily','week'=>'Weekly','month'=>'Monthly'] as $value=>$label)<option value="{{ $value }}" @selected(request('interval','auto')===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="col-6 col-lg-3"><button class="btn btn-primary w-100" type="submit">Apply filters</button></div>
<div class="col-12"><input type="hidden" name="compare" value="0"><label class="d-flex gap-2 align-items-center"><input id="report-compare" type="checkbox" name="compare" value="1" @checked($compare)> Compare with the previous period of equal length</label></div>
</div>
</form>
<p id="report-status" role="status" aria-live="polite" class="small text-muted">Filters update every chart and breakdown. Hover or tap a chart for exact values.</p>
<div id="report-error" role="alert" class="alert alert-danger" hidden></div>
<div id="report-results">@include('reports.results')</div>
<script id="report-chart-data" type="application/json">@json($analytics)</script>
</div>
@endsection
@push('scripts')<script src="{{ asset('js/reports.js') }}" defer></script>@endpush
