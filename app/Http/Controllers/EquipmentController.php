<?php

namespace App\Http\Controllers;

use App\Models\BusinessUser;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentMaintenance;
use App\Models\Shoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EquipmentController extends Controller
{
    public function index(): View
    {
        return view('equipment.index', ['equipment' => Equipment::forBusiness(app('currentBusiness')->id)->paginate(20)]);
    }

    public function create(): View
    {
        return view('equipment.form', ['equipment' => new Equipment]);
    }

    public function show(Equipment $equipment): View
    {
        $this->guard($equipment);
        $businessId = $equipment->business_id;

        return view('equipment.show', ['equipment' => $equipment, 'assignments' => EquipmentAssignment::with(['shoot', 'staff.user'])->where('equipment_id', $equipment->id)->latest('assigned_at')->get(), 'maintenance' => EquipmentMaintenance::where('equipment_id', $equipment->id)->latest('maintenance_date')->get(), 'shoots' => Shoot::forBusiness($businessId)->whereNotIn('status', ['delivered'])->latest('shoot_date')->limit(50)->get(), 'staff' => BusinessUser::with('user')->where('business_id', $businessId)->where('status', 'active')->get()]);
    }

    public function store(Request $r): RedirectResponse
    {
        $e = Equipment::create($this->data($r) + ['business_id' => app('currentBusiness')->id, 'equipment_code' => $this->code()]);

        return redirect()->route('equipment.edit', $e)->with('success', 'Equipment added.');
    }

    public function edit(Equipment $equipment): View
    {
        $this->guard($equipment);

        return view('equipment.form', compact('equipment'));
    }

    public function update(Request $r, Equipment $equipment): RedirectResponse
    {
        $this->guard($equipment);
        $equipment->update($this->data($r));

        return back()->with('success', 'Equipment updated.');
    }

    public function maintenance(Request $r, Equipment $equipment): RedirectResponse
    {
        $this->guard($equipment);
        $data = $r->validate(['maintenance_date' => 'required|date', 'problem' => 'required|string|max:2000', 'repair_company' => 'nullable|string|max:150', 'cost' => 'required|numeric|min:0', 'next_maintenance_date' => 'nullable|date|after_or_equal:maintenance_date', 'notes' => 'nullable|string']);
        EquipmentMaintenance::create($data + ['business_id' => $equipment->business_id, 'equipment_id' => $equipment->id]);
        $equipment->update(['status' => 'maintenance']);

        return back()->with('success', 'Maintenance recorded.');
    }

    public function assign(Request $r, Equipment $equipment): RedirectResponse
    {
        $this->guard($equipment);
        abort_if(EquipmentAssignment::where('equipment_id', $equipment->id)->whereNull('returned_at')->exists(), 422, 'This equipment is already assigned.');
        $data = $r->validate(['shoot_id' => ['nullable', Rule::exists('shoots', 'id')->where('business_id', $equipment->business_id)], 'business_user_id' => ['nullable', Rule::exists('business_user', 'id')->where('business_id', $equipment->business_id)], 'notes' => 'nullable|string']);
        abort_unless($data['shoot_id'] ?? $data['business_user_id'] ?? false, 422, 'Choose a shoot or staff member.');
        EquipmentAssignment::create($data + ['business_id' => $equipment->business_id, 'equipment_id' => $equipment->id, 'assigned_at' => now()]);
        $equipment->update(['status' => 'assigned']);

        return back()->with('success', 'Equipment assigned.');
    }

    public function returnAssignment(Equipment $equipment, EquipmentAssignment $assignment): RedirectResponse
    {
        $this->guard($equipment);
        abort_unless($assignment->equipment_id === $equipment->id && $assignment->business_id === $equipment->business_id, 404);
        abort_if($assignment->returned_at, 422, 'This assignment is already closed.');
        $assignment->update(['returned_at' => now()]);
        $equipment->update(['status' => 'available']);

        return back()->with('success', 'Equipment returned.');
    }

    private function data(Request $r): array
    {
        return $r->validate(['name' => 'required|string|max:150', 'type' => 'required|string|max:100', 'brand' => 'nullable|string|max:100', 'model' => 'nullable|string|max:100', 'serial_number' => 'nullable|string|max:150', 'purchase_date' => 'nullable|date', 'cost' => 'nullable|numeric|min:0', 'condition' => 'required|in:excellent,good,fair,damaged', 'status' => 'required|in:available,assigned,in_use,maintenance,damaged,lost']);
    }

    private function code(): string
    {
        return 'EQ-'.str_pad((string) ((Equipment::forBusiness(app('currentBusiness')->id)->max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }

    private function guard(Equipment $e): void
    {
        abort_unless($e->business_id === app('currentBusiness')->id, 404);
    }
}
