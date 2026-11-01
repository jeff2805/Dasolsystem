<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture sent with a message.
 *
 * THE FILE GOES ON THE PRIVATE DISK, and this table only records
 * where. A photograph a guest sends a resort — a receipt, a booking
 * screenshot, a child's face — is nobody else's business, and on the
 * public disk the web server hands it to anyone with the URL, with no
 * session and no check. This project has already had that exact
 * mistake with GCash proofs; the path is stored here and served only
 * through a controller that authorises first.
 *
 * original_name is kept so a download can be offered under the name
 * the sender used, without ever putting that name on disk, where it
 * would be a path somebody else chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('message_id')->constrained()->cascadeOnDelete();

            // Relative to the 'local' disk. Never a URL.
            $table->string('path');

            $table->string('original_name', 180)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
