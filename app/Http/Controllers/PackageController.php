<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackageRequest;
use App\Models\Package;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(Request $r): View
    {
        $q = Package::forBusiness(app('currentBusiness')->id)->latest();
        if ($s = $r->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('category', 'like', "%$s%"));
        }

return view('packages.index', ['packages' => $q->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        return view('packages.form', ['package' => new Package]);
    }

    public function store(PackageRequest $r): RedirectResponse
    {
        $p = Package::create($r->validated() + ['business_id' => app('currentBusiness')->id]);

        return redirect()->route('packages.index')->with('success', 'Package created.');
    }

    public function edit(Package $package): View
    {
        $this->guard($package);

        return view('packages.form', compact('package'));
    }

    public function update(PackageRequest $r, Package $package): RedirectResponse
    {
        $this->guard($package);
        $package->update($r->validated());

        return redirect()->route('packages.index')->with('success', 'Package updated.');
    }

    public function destroy(Package $package): RedirectResponse
    {
        $this->guard($package);
        $package->delete();

        return redirect()->route('packages.index')->with('success', 'Package archived.');
    }

    private function guard(Package $m): void
    {
        abort_unless($m->business_id === app('currentBusiness')->id, 404);
    }
}
