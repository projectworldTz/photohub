@extends('layouts.app')
@section('title',$business->name)
@section('content')
<x-page-header :title="$business->name" :subtitle="$business->email">
    <div class="d-flex gap-2">
        @unless($business->cloud_identity_only)<form method="POST" action="{{ route('admin.impersonation.start',$business) }}">@csrf<button class="btn btn-primary" @disabled($business->status!=='active')><i class="bi bi-eye"></i> View as owner</button></form>@endunless
        <a class="btn btn-light" href="{{ route('admin.businesses.edit',$business) }}">Edit</a>
    </div>
</x-page-header>
<section class="content-card mb-3">
<h2 class="h5">Cloud studio #{{ $business->id }}</h2>
<p>{{ $business->cloud_identity_only ? 'Lightweight sharing identity' : 'Studio account' }} &middot; {{ ucfirst($business->status) }}</p>
<p class="small">Registered: {{ $business->created_at }} &middot; Last cloud activity: {{ $business->cloud_last_seen_at ?? 'Never' }} &middot; App version: {{ $business->app_version ?? 'Not reported' }}</p>
<p class="small">API token: {{ \Illuminate\Support\Facades\DB::table('studio_api_tokens')->where('business_id', $business->id)->whereNull('revoked_at')->exists() ? 'Active' : 'Not active' }}</p>
<form method="POST" action="{{ route('admin.businesses.revoke-cloud-token', $business) }}" data-feedback-form data-loading="Revoking credentials...">@csrf<button class="btn btn-sm btn-outline-danger">Revoke API credentials</button></form>
@if($business->cloud_identity_only)
<details class="mt-3"><summary>Online galleries</summary><div class="table-responsive"><table class="table table-sm mt-2"><thead><tr><th>Gallery</th><th>Status</th><th>Expires</th></tr></thead><tbody>@forelse($business->galleries()->where('cloud_replica', true)->latest()->limit(100)->get() as $onlineGallery)<tr><td>{{ $onlineGallery->name }}</td><td>{{ $onlineGallery->status }}</td><td>{{ $onlineGallery->expires_at?->format('Y-m-d') }}</td></tr>@empty<tr><td colspan="3">No shared galleries.</td></tr>@endforelse</tbody></table></div></details>
@endif
</section>
<div class="row g-3 mb-3">@foreach($counts as $name=>$value)<div class="col-md-3"><div class="metric-card"><div><small>{{ str($name)->title() }}</small><h3>{{ $name==='revenue'?$business->currency.' '.number_format($value):$value }}</h3></div></div></div>@endforeach</div>
<x-storage-usage :business="$business" />
@php($limitGb = (int) ceil($business->storage_limit_bytes / \App\Services\StorageQuotaService::GB))
<form method="POST" action="{{ route('admin.businesses.storage', $business) }}" class="content-card mb-3">
    @csrf @method('PATCH')
    <h4>Storage Allocation</h4>
    <label for="storage-slider" class="form-label">Storage Limit: <strong id="storage-selected">{{ old('storage_limit_gb', $limitGb) }} GB</strong></label>
    <input id="storage-slider" type="range" min="1" max="{{ max(50, $limitGb) }}" step="1" value="{{ old('storage_limit_gb', $limitGb) }}" class="form-range">
    <div class="d-flex justify-content-between small text-muted"><span>1 GB</span><span id="storage-maximum">{{ max(50, $limitGb) }} GB</span></div>
    <label for="storage-number" class="form-label mt-2">Exact allocation (GB)</label>
    <input id="storage-number" name="storage_limit_gb" type="number" min="1" max="1048576" step="1" value="{{ old('storage_limit_gb', $limitGb) }}" class="form-control" required>
    <p class="small text-muted mt-2">Reducing the limit below usage retains all files and pauses new uploads until enough space is available.</p>
    <button class="btn btn-primary">Save storage allocation</button>
</form>
@push('scripts')
<script>
const storageSlider = document.getElementById('storage-slider'), storageNumber = document.getElementById('storage-number'), storageSelected = document.getElementById('storage-selected');
storageSlider.addEventListener('input', () => { storageNumber.value = storageSlider.value; storageSelected.textContent = storageSlider.value + ' GB'; });
storageNumber.addEventListener('input', () => { storageSlider.max = Math.max(50, Number(storageNumber.value) || 50); document.getElementById('storage-maximum').textContent = storageSlider.max + ' GB'; storageSlider.value = storageNumber.value; storageSelected.textContent = storageNumber.value + ' GB'; });
</script>
@endpush
<div class="content-card"><h4>Users</h4>@foreach($business->users as $user)<p>{{ $user->name }} · {{ $user->email }}</p>@endforeach<hr><h4>Recent activity</h4>@foreach($business->activityLogs->sortByDesc('id')->take(20) as $log)<p>{{ str($log->action)->replace(['.','_'],' ')->title() }} <small>{{ $log->created_at->diffForHumans() }}</small></p>@endforeach</div>
<form method="POST" action="{{ route('admin.businesses.destroy',$business) }}" class="mt-3">@csrf @method('DELETE')<button class="btn btn-outline-danger" onclick="return confirm('Archive this business?')">Archive business</button></form>
@endsection
