@extends('layouts.app')
@section('title','Equipment')
@section('content')
<x-page-header title="Equipment"><a href="{{ route('equipment.create') }}" class="btn btn-primary">Add equipment</a></x-page-header><div class="content-card table-responsive"><table class="table"><thead><tr><th>Code</th><th>Item</th><th>Condition</th><th>Status</th><th></th></tr></thead><tbody>@forelse($equipment as $e)<tr><td>{{ $e->equipment_code }}</td><td>{{ $e->name }}<small class="d-block">{{ $e->brand }} {{ $e->model }}</small></td><td>{{ ucfirst($e->condition) }}</td><td>{{ str($e->status)->replace('_',' ')->title() }}</td><td><a href="{{ route('equipment.show',$e) }}">Manage</a></td></tr>@empty<tr><td colspan="5">No equipment.</td></tr>@endforelse</tbody></table>{{ $equipment->links() }}</div>
@endsection
