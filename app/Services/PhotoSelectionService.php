<?php

namespace App\Services;

use App\Events\PhotoSelectionCompleted;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhotoSelectionService
{
    public function toggle(Gallery $g, Photo $p, int $customerId): bool
    {
        abort_unless($p->gallery_id === $g->id, 404);
        if ($g->selection_completed_at) {
            throw ValidationException::withMessages(['selection' => 'This selection has been submitted and locked. Please contact your photographer to reopen it.']);
        }

        return DB::transaction(function () use ($g, $p, $customerId) {
            $q = DB::table('photo_selections')->where(['photo_id' => $p->id, 'customer_id' => $customerId]);
            if ($q->exists()) {
                $q->delete();

                return false;
            }$count = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $g->id)->where('customer_id', $customerId)->count();
            if ($g->selection_limit !== null && $count >= $g->selection_limit && (float) $g->extra_photo_price <= 0) {
                throw ValidationException::withMessages(['selection' => 'You can only select '.$g->selection_limit.' photos with your current package.']);
            }DB::table('photo_selections')->insert(['photo_id' => $p->id, 'customer_id' => $customerId, 'selected_at' => now()]);

            return true;
        });
    }

    public function complete(Gallery $gallery, int $customerId): ?Invoice
    {
        $count = DB::table('photo_selections')->join('photos', 'photos.id', '=', 'photo_selections.photo_id')->where('photos.gallery_id', $gallery->id)->where('customer_id', $customerId)->count();
        if ($count === 0) {
            throw ValidationException::withMessages(['selection' => 'Select at least one photo before completing your selection.']);
        }
        if ($gallery->require_exact_selection && $gallery->selection_limit !== null && $count !== $gallery->selection_limit) {
            throw ValidationException::withMessages(['selection' => "Please select exactly {$gallery->selection_limit} photos before submitting."]);
        }
        $extra = max(0, $count - (int) ($gallery->selection_limit ?? $count));
        $note = 'Extra photo selection for '.$gallery->name;
        $alreadyBilled = (float) DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.business_id', $gallery->business_id)->where('invoices.customer_id', $customerId)
            ->where('invoices.notes', $note)->where('invoices.status', '!=', 'cancelled')->sum('invoice_items.quantity');
        $newExtras = max(0, $extra - $alreadyBilled);
        $invoice = null;
        if (! $gallery->cloud_replica && $newExtras > 0 && (float) $gallery->extra_photo_price > 0) {
            $invoice = app(InvoiceService::class)->create([
                'business_id' => $gallery->business_id,
                'customer_id' => $customerId,
                'booking_id' => $gallery->booking_id,
                'discount' => 0,
                'tax' => 0,
                'due_date' => today()->addDays(7),
                'notes' => $note,
            ], [['description' => 'Additional selected photos', 'quantity' => $newExtras, 'unit_price' => $gallery->extra_photo_price]]);
        }
        $gallery->update(['status' => 'selection_submitted', 'selection_completed_at' => now(), 'submitted_selection_count' => $count]);
        PhotoSelectionCompleted::dispatch($gallery);

        return $invoice;
    }
}
