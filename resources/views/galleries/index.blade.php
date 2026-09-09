@extends('layouts.app')
@section('title','Galleries')
@section('content')
<x-page-header title="Galleries">
    <div class="d-flex gap-2"><a href="{{ route('galleries.links.index') }}" class="btn btn-outline-primary"><i class="bi bi-link-45deg"></i> Manage links</a><a href="{{ route('galleries.create') }}" class="btn btn-primary">New gallery</a></div>
</x-page-header>
<form class="content-card gallery-filters mb-3" role="search" aria-label="Filter galleries">
    <div class="row g-2">
        <div class="col-lg-4"><input name="search" value="{{ request('search') }}" class="form-control" placeholder="Name, event or number"></div>
        <div class="col-6 col-lg-2"><select name="type" class="form-select"><option value="">All types</option>@foreach(['selection','final_delivery','proof','final','public_event','private_event','portfolio'] as $type)<option value="{{ $type }}" @selected(request('type')===$type)>{{ str($type)->replace('_',' ')->title() }}</option>@endforeach</select></div>
        <div class="col-6 col-lg-2"><select name="status" class="form-select"><option value="">All statuses</option>@foreach(['draft','proofs_uploading','proofs_ready','selection_link_ready','selection_sent','customer_selecting','selection_submitted','selection_reopened','editing','final_upload_in_progress','final_ready','final_published','delivered','expired','archived'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ str($status)->replace('_',' ')->title() }}</option>@endforeach</select></div>
        <div class="col-9 col-lg-3"><select name="customer_id" class="form-select"><option value="">All customers</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(request('customer_id')==$customer->id)>{{ $customer->full_name }}</option>@endforeach</select></div>
        <div class="col-3 col-lg-1"><button class="btn btn-outline-primary w-100">Filter</button></div>
    </div>
</form>
<div class="row g-3">@forelse($galleries as $gallery)<div class="col-sm-6 col-xl-4"><div class="content-card h-100"><span class="badge text-bg-light">{{ str($gallery->type)->replace('_',' ')->title() }}</span><h4 class="mt-3">{{ $gallery->name }}</h4><x-gallery-expiry :gallery="$gallery" /><p>{{ $gallery->type === 'final_delivery' ? $gallery->final_photos_count : $gallery->photos_count }} photos · {{ str($gallery->status)->replace('_',' ')->title() }}</p><small class="d-block">{{ $gallery->customer?->full_name ?: 'No customer' }}</small><a class="btn btn-sm btn-light mt-3" href="{{ route($gallery->type === 'final_delivery' ? 'galleries.workflow' : 'galleries.show',$gallery) }}">Manage gallery</a></div></div>@empty<div class="col-12"><div class="content-card text-center text-muted">No galleries match these filters.</div></div>@endforelse</div>
{{ $galleries->links() }}
@endsection
