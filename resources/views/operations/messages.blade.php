@extends('layouts.app')
@section('title','Messages')
@section('content')
<x-page-header title="Customer messages"/>
<form method="POST" action="{{ route('messages.store') }}" class="content-card mb-3">@csrf
    <div class="row g-2"><div class="col-md-3"><select name="customer_id" class="form-select" required><option value="">Choose customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->full_name }}</option>@endforeach</select></div><div class="col-md-7"><textarea name="body" class="form-control" rows="2" placeholder="Write a reply" required></textarea></div><div class="col-md-2"><button class="btn btn-primary">Send reply</button></div></div>
</form>
<div class="content-card">@forelse($messages as $m)<div class="border-bottom py-3"><span class="badge {{ $m->sender_id===auth()->id()?'text-bg-primary':'text-bg-light' }}">{{ $m->sender_id===auth()->id()?'Studio':'Customer' }}</span><p class="mb-1 mt-2">{{ $m->body }}</p><small>{{ $m->created_at->format('M j, Y H:i') }}</small></div>@empty<p>No messages.</p>@endforelse{{ $messages->links() }}</div>
@endsection
