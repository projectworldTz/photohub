<!doctype html><html><head><link rel="icon" type="image/png" href="{{ asset('favicon.png') }}"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My PhotoHub portal</title><link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet"><link href="{{ asset('css/photohub.css') }}" rel="stylesheet"></head><body>
<nav class="navbar bg-white border-bottom"><div class="container"><span class="brand dark">PhotoHub</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-light">Sign out</button></form></div></nav>
<main class="container py-5">@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif<h1>Welcome, {{ $customer->full_name }}</h1>
<div class="row g-3 my-3">
    <div class="col-md-4"><div class="content-card"><h5>Bookings</h5>@forelse($bookings as $b)<p>{{ $b->event_type }} · {{ $b->event_date->format('M j, Y') }}</p>@empty<p>None yet.</p>@endforelse</div></div>
    <div class="col-md-4"><div class="content-card"><h5>Galleries</h5>@forelse($galleries as $g)<a class="d-block" href="{{ route('public.gallery',$g->code) }}">{{ $g->name }}</a>@empty<p>None yet.</p>@endforelse</div></div>
    <div class="col-md-4"><div class="content-card"><h5>Invoices</h5>@forelse($invoices as $i)<p>{{ $i->invoice_number }} · Balance {{ number_format((float)$i->balance) }}</p>@empty<p>None yet.</p>@endforelse</div></div>
</div>
<div class="row g-3 mb-3"><div class="col-12"><div class="content-card"><h5>Payments and receipts</h5>@forelse($payments as $payment)<p>{{ number_format((float)$payment->amount) }} · {{ str($payment->status)->title() }} @if($payment->receipt)<a href="{{ route('portal.receipts.pdf',$payment->receipt) }}">Receipt</a>@endif</p>@empty<p>None yet.</p>@endforelse</div></div></div>
</main></body></html>
