@extends('layouts.app')
@section('title',$business->name)
@section('content')
<x-page-header :title="$business->name" :subtitle="$business->email">
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('admin.impersonation.start',$business) }}">@csrf<button class="btn btn-primary" @disabled($business->status!=='active')><i class="bi bi-eye"></i> View as owner</button></form>
        <a class="btn btn-light" href="{{ route('admin.businesses.edit',$business) }}">Edit</a>
    </div>
</x-page-header>
<div class="row g-3 mb-3">@foreach($counts as $name=>$value)<div class="col-md-3"><div class="metric-card"><div><small>{{ str($name)->title() }}</small><h3>{{ $name==='revenue'?$business->currency.' '.number_format($value):$value }}</h3></div></div></div>@endforeach</div>
<div class="content-card"><h4>Users</h4>@foreach($business->users as $user)<p>{{ $user->name }} · {{ $user->email }}</p>@endforeach<hr><h4>Recent activity</h4>@foreach($business->activityLogs->sortByDesc('id')->take(20) as $log)<p>{{ str($log->action)->replace(['.','_'],' ')->title() }} <small>{{ $log->created_at->diffForHumans() }}</small></p>@endforeach</div>
<form method="POST" action="{{ route('admin.businesses.destroy',$business) }}" class="mt-3">@csrf @method('DELETE')<button class="btn btn-outline-danger" onclick="return confirm('Archive this business?')">Archive business</button></form>
@endsection
