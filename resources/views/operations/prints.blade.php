@extends('layouts.app')
@section('title','Print orders')
@section('content')
<x-page-header title="Print orders"/>
<form method="POST" action="{{ route('prints.store') }}" class="content-card mb-3 row g-2">@csrf
    <div class="col-md-3"><select name="customer_id" class="form-select" required><option value="">Customer</option>@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->full_name }}</option>@endforeach</select></div>
    <div class="col-md-2"><select name="product" class="form-select">@foreach(['printed_photo','album','frame','canvas','graduation_book'] as $p)<option value="{{ $p }}">{{ str($p)->replace('_',' ')->title() }}</option>@endforeach</select></div>
    <div class="col-md-2"><input name="size" placeholder="Size" class="form-control" required></div>
    <div class="col-md-2"><input name="quantity" type="number" min="1" value="1" class="form-control" required></div>
    <div class="col-md-2"><input name="total" type="number" min="0" step="0.01" placeholder="Total" class="form-control" required></div>
    <div class="col-md-1"><button class="btn btn-primary">Create</button></div>
</form>
<div class="content-card table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th>Size</th><th>Qty</th><th>Total</th><th>Status</th></tr></thead><tbody>@forelse($orders as $o)<tr><td>{{ str($o->product)->replace('_',' ')->title() }}</td><td>{{ $o->size }}</td><td>{{ $o->quantity }}</td><td>{{ number_format((float)$o->total,2) }}</td><td><form method="POST" action="{{ route('prints.update',$o) }}">@csrf @method('PATCH')<select name="status" class="form-select" onchange="this.form.submit()">@foreach(['pending','designing','printing','ready','delivered','cancelled'] as $s)<option @selected($o->status===$s)>{{ $s }}</option>@endforeach</select></form></td></tr>@empty<tr><td colspan="5">No print orders yet.</td></tr>@endforelse</tbody></table>{{ $orders->links() }}</div>
@endsection
