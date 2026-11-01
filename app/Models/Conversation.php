<?php

namespace App\Models;

use App\Enums\MessageSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A thread between one tourist and one resort.
 *
 * Nothing here is mass assignable. A conversation is opened by
 * MessagingService, which decides the pair; letting a request fill
 * tourist_id would be letting it choose whose inbox to write into.
 */
class Conversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function tourist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tourist_id');
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    /** Optional context: the stay being discussed, if there is one. */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The newest message, for an inbox row. */
    public function latestMessage(): HasMany
    {
        return $this->messages()->latest('id')->limit(1);
    }

    /* Who is who ---------------------------------------------------------- */

    /**
     * Which side this account is on, or null if they are on neither.
     *
     * THE OWNER IS READ FROM THE RESORT, NOT STORED. A listing that
     * changes hands takes its conversations with it, which is what a
     * guest writing to a business expects.
     */
    public function sideFor(?User $user): ?MessageSender
    {
        if ($user === null) {
            return null;
        }

        if ((int) $this->tourist_id === (int) $user->id) {
            return MessageSender::Tourist;
        }

        if ((int) $this->resort?->owner_id === (int) $user->id) {
            return MessageSender::Owner;
        }

        return null;
    }

    public function includes(?User $user): bool
    {
        return $this->sideFor($user) !== null;
    }

    /** What this account has not read yet. */
    public function unreadFor(User $user): int
    {
        $side = $this->sideFor($user);

        return $side === null ? 0 : (int) $this->{$side->unreadColumn()};
    }

    /* Scopes --------------------------------------------------------------- */

    /** Threads this account is a party to, whichever side they are on. */
    public function scopeForParticipant(Builder $query, User $user): void
    {
        $query->where(function (Builder $where) use ($user): void {
            $where->where('tourist_id', $user->id)
                ->orWhereHas('resort', fn (Builder $resort) => $resort->where('owner_id', $user->id));
        });
    }

    /** Newest first, with threads that have no message at all last. */
    public function scopeRecent(Builder $query): void
    {
        $query->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }
}
