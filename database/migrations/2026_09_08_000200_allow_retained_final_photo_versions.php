<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_photos', function (Blueprint $table) {
            // Keep proof associations on retained versions. The studio upload
            // transaction serializes replacement and leaves one active version.
            $table->index(['gallery_id', 'proof_photo_id'], 'final_photos_gallery_proof_index');
            $table->dropUnique(['gallery_id', 'proof_photo_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retained final photo versions must not be discarded to restore the old unique constraint. Use a forward migration.');
    }
};
