@if($cloudConnection)
<section class="content-card mb-3" id="cloud-connection">
    <h2 class="h5">Cloud Connection</h2>
    <p class="small text-muted">Your studio connects automatically when you first share a gallery. You can also connect and test it here. Local work remains available offline.</p>
    <p role="status"><strong>{{ ['connected' => 'Connected', 'failed' => 'Connection Failed'][$cloudConnection->status] ?? 'Not Connected' }}</strong> &middot; Last Connected: {{ $cloudConnection->last_connected_at?->format('Y-m-d H:i') ?? 'Never' }}</p>
    @if($cloudConnection->cloud_studio_id)<p class="small">Cloud Studio ID: {{ $cloudConnection->cloud_studio_id }}</p>@endif
    @if($cloudConnection->last_error)<p class="text-danger small">{{ $cloudConnection->last_error }}</p>@endif
    <form method="POST" action="{{ route('settings.cloud.test') }}" data-feedback-form data-loading="Connecting studio..." data-success="Studio connected successfully.">
        @csrf
        <label for="cloud-base-url" class="form-label">PhotoHub Cloud address</label>
        <input id="cloud-base-url" name="base_url" type="url" class="form-control mb-2" value="{{ $cloudConnection->base_url }}" required @readonly($cloudConnection->api_token)>
        <details class="small mb-3"><summary>Recover an existing connection</summary>
            <p class="text-muted mt-2">Only use this if the cloud administrator has issued replacement credentials. Saved tokens are hidden.</p>
            <label for="cloud-api-token" class="form-label">Replacement studio token</label>
            <input id="cloud-api-token" name="cloud_api_token" type="password" autocomplete="new-password" class="form-control" value="">
        </details>
        <button class="btn btn-primary">Test Connection</button>
    </form>
</section>
@endif
