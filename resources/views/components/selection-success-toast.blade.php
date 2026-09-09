@if(session('selection_success') && session('success'))
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090;max-width:100%">
        <div id="selection-success-toast" class="toast show border-0 shadow-lg" role="status" aria-live="polite" aria-atomic="true" style="width:390px;max-width:100%;border-radius:16px;background:#fff;border-left:5px solid #198754!important">
            <div class="d-flex align-items-start gap-3 p-3">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width:42px;height:42px;background:#e7f6ed;color:#146c43" aria-hidden="true"><i class="bi bi-check-lg fs-4"></i></span>
                <div class="flex-grow-1">
                    <strong class="d-block mb-1" style="color:#146c43;font-size:1rem">Selection sent successfully!</strong>
                    <p class="mb-0" style="color:#46534c;font-size:.875rem;line-height:1.5">{{ session('success') }}</p>
                </div>
                <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="toast" aria-label="Dismiss success message"></button>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const element = document.getElementById('selection-success-toast');
            if (element && window.bootstrap) {
                bootstrap.Toast.getOrCreateInstance(element, { delay: 10000 }).show();
            }
        });
    </script>
@endif
