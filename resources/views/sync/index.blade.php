@extends('layouts.app')
@section('title','Cloud synchronization')
@section('content')
<x-page-header title="Cloud synchronization" subtitle="Originals remain on this computer. Only previews and final edited photos are published." />
<div class="content-card mb-3"><p>Cloud Connection: <strong>{{ $connection }}</strong></p><form method="POST" action="{{ route('sync.connection') }}">@csrf<button class="btn btn-primary">Check Connection</button></form><p class="mt-3 mb-0">Pending operations: {{ $pending }} · Failed operations: {{ $failed }} · Synced galleries: {{ $synced }}</p></div>
<x-storage-usage :business="$currentBusiness" />
@foreach($galleries as $gallery)
<h4><a href="{{ route('galleries.show',$gallery) }}">{{ $gallery->name }}</a></h4>
<p>Local photos: {{ $gallery->photos_count }} · Cloud previews: {{ $gallery->cloud_photos_count }} · Edited photos: {{ $gallery->final_photos_count }}</p>
<x-gallery-sync :gallery="$gallery" />
@endforeach
{{ $galleries->links() }}
@endsection
