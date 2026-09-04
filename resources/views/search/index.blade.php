@extends('layouts.app')
@section('title','Search')
@section('content')
<x-page-header title="Global search" subtitle="Customers, bookings, invoices, galleries and shoots"/>
<form class="content-card mb-3"><div class="input-group"><input name="q" value="{{ $term }}" class="form-control" minlength="2" placeholder="Name, number, event or location" autofocus><button class="btn btn-primary">Search</button></div></form>
<div class="content-card">@if(mb_strlen($term)<2)<p>Enter at least two characters.</p>@else @forelse($results as $result)<a class="d-flex justify-content-between border-bottom py-3 text-decoration-none" href="{{ $result['url'] }}"><span>{{ $result['label'] }}</span><span class="badge text-bg-light">{{ $result['type'] }}</span></a>@empty<p>No records matched “{{ $term }}”.</p>@endforelse @endif</div>
@endsection
