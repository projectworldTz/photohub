@props(['business', 'compact' => false])
@php($storage = app(\App\Services\StorageQuotaService::class)->usage($business))
@if(\App\Services\PhotoStorage::isLocal())
<div class="content-card storage-local {{ $compact ? 'storage-local-compact' : '' }} mb-3"><h5>Local storage</h5>@php($localBytes = \Illuminate\Support\Facades\DB::table('photos')->where('business_id',$business->id)->sum('file_size') + \Illuminate\Support\Facades\DB::table('final_photos')->where('business_id',$business->id)->sum('file_size'))<strong>{{ \App\Services\StorageQuotaService::format($localBytes) }} originals and edited files (previews additional)</strong><p>Originals, previews and edited photos stay on this computer and do not consume cloud quota.</p>
@php($cloudStorage = \Illuminate\Support\Facades\Cache::get('photohub-cloud-storage-'.$business->id))
<p>Cloud storage: @if($cloudStorage){{ \App\Services\StorageQuotaService::format($cloudStorage['used']) }} / {{ \App\Services\StorageQuotaService::format($cloudStorage['limit']) }} (last connection check)@else Check Connection on the sync dashboard to retrieve usage.@endif</p></div>
@elseif($compact)
<div class="overview-storage mb-3">
    <div class="overview-storage-label"><strong>Storage Usage</strong><span class="badge text-bg-{{ $storage['color'] }}">{{ $storage['status'] }}</span></div>
    <div class="overview-storage-meter">
        <div class="d-flex justify-content-between gap-2 small"><span>{{ \App\Services\StorageQuotaService::format($storage['used']) }} of {{ \App\Services\StorageQuotaService::format($storage['limit']) }}</span><span>{{ $storage['percent'] }}% used</span></div>
        <div class="progress mt-1" role="progressbar" aria-label="Storage used" aria-valuenow="{{ min(100, $storage['percent']) }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-{{ $storage['color'] }}" style="width:{{ min(100, $storage['percent']) }}%"></div></div>
    </div>
    <small class="text-muted">{{ \App\Services\StorageQuotaService::format($storage['remaining']) }} remaining</small>
</div>
@else
<div class="content-card mb-3">
    <div class="d-flex justify-content-between"><h5>Storage Usage</h5><span class="badge text-bg-{{ $storage['color'] }}">{{ $storage['status'] }}</span></div>
    <strong>{{ \App\Services\StorageQuotaService::format($storage['used']) }} / {{ \App\Services\StorageQuotaService::format($storage['limit']) }}</strong>
    <div class="progress my-2" role="progressbar" aria-label="Storage used" aria-valuenow="{{ min(100, $storage['percent']) }}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-{{ $storage['color'] }}" style="width:{{ min(100, $storage['percent']) }}%"></div></div>
    <small>{{ $storage['percent'] }}% used · Remaining: {{ \App\Services\StorageQuotaService::format($storage['remaining']) }}</small>
    <small class="d-block text-muted">Expired, archived and replaced photos count while their files are retained.</small>
</div>
@endif
