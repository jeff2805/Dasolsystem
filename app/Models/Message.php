<?php

namespace App\Models;

use App\Enums\MessageSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in a thread.
 *
 * Guarded rather than fillable: the sender and the thread are decided
 * by MessagingService from the signed-in account, never taken from a
 * form. A request that could set sender_id could write a message as
 * somebody else.
 */
class Message extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sender_role' => MessageSender::class,
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** May be null: the account was closed after writing this. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /** Whether this account wrote it, for sided bubbles in the thread. */
    public function wasSentBy(?User $user): bool
    {
        return $user !== null
            && $this->sender_id !== null
            && (int) $this->sender_id === (int) $user->id;
    }
}
