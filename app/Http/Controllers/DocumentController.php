<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function invoice(Invoice $invoice)
    {
        $this->guard($invoice);

        return Pdf::loadView('documents.financial', ['type' => 'Invoice', 'document' => $invoice->load(['customer', 'items']), 'business' => app('currentBusiness')])->download($invoice->invoice_number.'.pdf');
    }

    public function quotation(Quotation $quotation)
    {
        $this->guard($quotation);

        return Pdf::loadView('documents.financial', ['type' => 'Quotation', 'document' => $quotation->load(['customer', 'items']), 'business' => app('currentBusiness')])->download($quotation->quotation_number.'.pdf');
    }

    public function receipt(Receipt $receipt)
    {
        $this->guard($receipt);

        return Pdf::loadView('documents.receipt', ['receipt' => $receipt->load(['customer', 'invoice', 'order', 'payment']), 'business' => app('currentBusiness')])->download($receipt->receipt_number.'.pdf');
    }

    public function report(Request $request, ReportService $service)
    {
        $from = Carbon::parse($request->input('from', now()->startOfYear()));
        $to = Carbon::parse($request->input('to', now()));
        $report = $service->financial(app('currentBusiness')->id, $from, $to);

        return Pdf::loadView('documents.report', ['report' => $report, 'from' => $from, 'to' => $to, 'business' => app('currentBusiness')])->download('photohub-report.pdf');
    }

    private function guard($model): void
    {
        abort_unless($model->business_id === app('currentBusiness')->id, 404);
    }
}
