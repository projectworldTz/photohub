@extends('layouts.app')
@section('title','Activity log')
@section('content')
<x-page-header title="Activity log" subtitle="Security and operational audit trail"/>
<div class="content-card table-responsive"><table class="table"><thead><tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>IP address</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ $log->created_at->format('M j, Y H:i') }}</td><td>{{ $log->user?->name ?: 'System' }}</td><td>{{ str($log->action)->replace(['.','_'],' ')->title() }}</td><td>{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</td><td>{{ $log->ip_address ?: '—' }}</td></tr>@empty<tr><td colspan="5">No activity recorded.</td></tr>@endforelse</tbody></table>{{ $logs->links() }}</div>
@endsection
