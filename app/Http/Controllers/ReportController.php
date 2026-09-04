<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $r, ReportService $s): View
    {
        $from = Carbon::parse($r->input('from', now()->startOfYear()));
        $to = Carbon::parse($r->input('to', now()->endOfDay()));

        $businessId = app('currentBusiness')->id;

        return view('reports.index', ['report' => $s->financial($businessId, $from, $to), 'breakdowns' => $s->breakdowns($businessId, $from, $to), 'from' => $from, 'to' => $to]);
    }

    public function csv(Request $r, ReportService $s): StreamedResponse
    {
        $from = Carbon::parse($r->input('from', now()->startOfYear()));
        $to = Carbon::parse($r->input('to', now()));
        $data = $s->financial(app('currentBusiness')->id, $from, $to);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Metric', 'Amount']);
            foreach ($data as $k => $v) {
                fputcsv($out, [str($k)->title(), $v]);
            }fclose($out);
        }, 'photohub-report.csv', ['Content-Type' => 'text/csv']);
    }
}
