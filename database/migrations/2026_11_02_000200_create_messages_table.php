<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One message in a thread.
 *
 * sender_id is the account that wrote it, and sender_role records
 * which side of the conversation they were on AT THE TIME. Both are
 * kept on purpose: the id can be followed to a person, and the role
 * still reads correctly if that person's account later changes hands
 * or their role changes. Working the side out from the current owner
 * of the resort would silently rewrite the history of who said what.
 *
 * read_at is per message rather than a pointer on the conversation,
 * so a read receipt can say exactly which message was seen.
 *
 * body is nullable: a message may be a picture with nothing typed.
 * The request layer refuses one that is neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            /*
             * The author. nullOnDelete rather than cascade: a closed
             * account must not silently delete half of somebody else's
             * conversation. The thread reads "Account removed" instead.
             */
            $table->foreignId('sender_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // 'tourist' or 'owner'. Which side, as it stood when sent.
            $table->string('sender_role', 10);

            $table->text('body')->nullable();

            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // A thread is read oldest-first; the inbox needs the newest.
            $table->index(['conversation_id', 'id'], 'messages_thread_order_index');

            // Counting what the other side has not seen yet.
            $table->index(['conversation_id', 'read_at'], 'messages_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
