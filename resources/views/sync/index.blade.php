@extends('layouts.app')
@section('title','Online Sharing')
@section('content')
<div class="cloud-sync-page">
    <x-page-header title="Online Sharing" subtitle="Work locally. Share a gallery online only when you choose." />
    <details class="mb-3">
        <summary class="small">Cloud connection &amp; storage</summary>
        <div class="content-card cloud-sync-card my-2">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small">{{ $connection }}</span>
                @if(auth()->user()->hasPermission('galleries.manage'))<form data-feedback-form data-loading="Checking cloud connection..." method="POST" action="{{ route('online.connection') }}">@csrf<button class="btn btn-sm btn-outline-primary">Check connection</button></form>@endif
            </div>
            <p class="small text-muted mt-2 mb-0">Connection checks confirm the server responds. Each sharing action reports its own progress.</p>
        </div>
        <x-storage-usage :business="$currentBusiness" compact />
    </details>
    @forelse($galleries as $gallery)
        <x-gallery-sync :gallery="$gallery" dashboard />
    @empty
        <p class="text-muted">No galleries yet. Create a local gallery and add photos to get started.</p>
    @endforelse
    {{ $galleries->links() }}
</div>
@endsection
