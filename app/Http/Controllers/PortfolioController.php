<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Models\PortfolioCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PortfolioController extends Controller
{
    public function index(): View
    {
        $id = app('currentBusiness')->id;

        return view('portfolio.index', ['categories' => PortfolioCategory::with('photos')->forBusiness($id)->get(), 'photos' => Photo::forBusiness($id)->where('status', 'ready')->latest()->limit(100)->get()]);
    }

    public function category(Request $r): RedirectResponse
    {
        $name = $r->validate(['name' => 'required|string|max:100'])['name'];
        PortfolioCategory::create(['business_id' => app('currentBusiness')->id, 'name' => $name, 'slug' => Str::slug($name)]);

        return back();
    }

    public function attach(Request $r): RedirectResponse
    {
        $id = app('currentBusiness')->id;
        $d = $r->validate(['category_id' => ['required', Rule::exists('portfolio_categories', 'id')->where('business_id', $id)], 'photo_id' => ['required', Rule::exists('photos', 'id')->where('business_id', $id)]]);
        PortfolioCategory::findOrFail($d['category_id'])->photos()->syncWithoutDetaching([$d['photo_id']]);

        return back();
    }
}
