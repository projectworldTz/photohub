<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\PhotoUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(): View
    {
        return view('expenses.index', ['expenses' => Expense::forBusiness(app('currentBusiness')->id)->latest('date')->paginate(20)]);
    }

    public function store(Request $r): RedirectResponse
    {
        abort_unless(auth()->user()->hasPermission('finance.manage'), 403);
        $d = $r->validate(['date' => 'required|date', 'category' => 'required|in:transport,fuel,equipment,editing,printing,staff,rent,internet,marketing,maintenance,other', 'amount' => 'required|numeric|min:.01', 'description' => 'required|string|max:2000', 'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240']);
        if ($r->hasFile('receipt')) {
            $d['receipt_path'] = app(PhotoUploadService::class)->storeFiles(app('currentBusiness'), ['receipt' => $r->file('receipt')], 'expense-receipts')['receipt'];
        }
        unset($d['receipt']);
        Expense::create($d + ['business_id' => app('currentBusiness')->id, 'recorded_by' => auth()->id()]);

        return back()->with('success', 'Expense recorded.');
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        abort_unless($expense->business_id === app('currentBusiness')->id && $expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path, basename($expense->receipt_path));
    }
}
