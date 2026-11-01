<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thread between one tourist and one resort.
 *
 * THE RESORT IS THE PARTY, NOT THE OWNER. An owner can change — a
 * listing is sold, an account is replaced — and the conversation
 * belongs to the business the guest wrote to, not to the person who
 * happened to hold the account that week. Who may read it is worked
 * out from resorts.owner_id at the time of asking.
 *
 * ONE THREAD PER PAIR, enforced by the unique index rather than by
 * remembering to check: two guests tapping "Message resort" twice in
 * the same second would otherwise each start one, and the two halves
 * of the conversation would never meet.
 *
 * booking_id is optional context, not ownership. A guest may write
 * before they book, and a thread opened from a reservation keeps the
 * link so the owner can see which stay is being discussed. The thread
 * outlives the booking: nullOnDelete, because losing the reservation
 * must not take the correspondence with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tourist_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resort_id')->constrained()->cascadeOnDelete();

            $table->foreignId('booking_id')->nullable()
                ->constrained()->nullOnDelete();

            /*
             * Denormalised so an inbox of fifty threads is one query.
             * Without it, listing conversations newest-first means a
             * subquery per row against a table that only grows.
             */
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();

            /*
             * Unread counts, kept per side.
             *
             * A counter rather than a scan of messages: the header asks
             * for this on every page load, and COUNT over unread
             * messages for every thread is the query that would make
             * the site feel slow first.
             */
            $table->unsignedSmallInteger('tourist_unread')->default(0);
            $table->unsignedSmallInteger('owner_unread')->default(0);

            $table->timestamps();

            // One thread per tourist per resort. See the note above.
            $table->unique(['tourist_id', 'resort_id'], 'conversations_tourist_resort_unique');

            // Both inboxes read newest-first, filtered to one party.
            $table->index(['tourist_id', 'last_message_at'], 'conversations_tourist_recent_index');
            $table->index(['resort_id', 'last_message_at'], 'conversations_resort_recent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
