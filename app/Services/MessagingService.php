<?php

namespace App\Services;

use App\Enums\MessageSender;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Resort;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opening threads, sending messages, and keeping the counters true.
 *
 * The one place any of that happens. Controllers authorise and then
 * call in here; nothing writes a message or touches an unread count
 * anywhere else, so the counters cannot drift from the messages they
 * are counting.
 */
class MessagingService
{
    /** Where a message picture lives. The PRIVATE disk, always. */
    private const DISK = 'local';

    private const FOLDER = 'message-attachments';

    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * The thread between this guest and this resort, opened if it is
     * not there yet.
     *
     * RACE-SAFE BY THE INDEX, NOT BY LOOKING FIRST. Two taps on
     * "Message resort" a moment apart both find nothing and both
     * insert; the unique index refuses the second, and the catch
     * below fetches the row the winner made. Checking first and
     * trusting the check is the bug this avoids.
     */
    public function between(User $tourist, Resort $resort, ?Booking $booking = null): Conversation
    {
        $existing = Conversation::query()
            ->where('tourist_id', $tourist->id)
            ->where('resort_id', $resort->id)
            ->first();

        if ($existing !== null) {
            // A thread opened cold, now being used from a reservation,
            // gains that context. An existing link is left alone: the
            // first booking it was about is the one it was about.
            if ($booking !== null && $existing->booking_id === null) {
                $existing->forceFill(['booking_id' => $booking->id])->save();
            }

            return $existing;
        }

        try {
            $conversation = new Conversation;

            $conversation->forceFill([
                'tourist_id' => $tourist->id,
                'resort_id' => $resort->id,
                'booking_id' => $booking?->id,
            ])->save();

            $this->logger->log(
                'conversation.opened',
                $conversation,
                'Opened a conversation with '.$resort->name,
                // The resort and the stay, never a word of what was said.
                ['resort_id' => $resort->id, 'booking_id' => $booking?->id]
            );

            return $conversation;
        } catch (QueryException $e) {
            /*
             * The other request won. Its row is the thread; this one
             * simply joins it. Rethrown if it was some other failure,
             * because swallowing a real database error here would hide
             * it behind a confusing "not found" later.
             */
            $raced = Conversation::query()
                ->where('tourist_id', $tourist->id)
                ->where('resort_id', $resort->id)
                ->first();

            if ($raced === null) {
                throw $e;
            }

            return $raced;
        }
    }

    /**
     * Send a message, with optional pictures.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function send(
        Conversation $conversation,
        User $sender,
        ?string $body,
        array $files = [],
    ): Message {
        $side = $conversation->sideFor($sender);

        /*
         * The controller has authorised already. This is the second
         * lock on the same door: a service that writes a message for
         * somebody who is not in the thread is one forgotten
         * authorize() away from being the whole vulnerability.
         */
        if ($side === null) {
            throw new \RuntimeException('That account is not part of this conversation.');
        }

        $body = $body === null ? null : Str::squish($body);
        $body = $body === '' ? null : $body;

        if ($body === null && $files === []) {
            throw new \RuntimeException('A message needs either words or a picture.');
        }

        return DB::transaction(function () use ($conversation, $sender, $side, $body, $files): Message {
            $message = new Message;

            $message->forceFill([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'sender_role' => $side->value,
                'body' => $body,
            ])->save();

            foreach ($files as $file) {
                $this->attach($message, $file);
            }

            /*
             * The inbox row, and the other side's unread count.
             *
             * The preview is the words, or a plain note that a picture
             * arrived. Trimmed to the column's width here rather than
             * trusting the database to truncate it, which on a strict
             * server is an error and on a loose one is a silent cut
             * through the middle of a character.
             */
            $preview = $body !== null
                ? Str::limit($body, 150, '…')
                : ($files === [] ? '' : 'Sent a picture');

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => $preview,
            ])->save();

            // Incremented in the database, not read-then-written, so two
            // messages arriving together both count.
            $conversation->newQuery()
                ->whereKey($conversation->getKey())
                ->increment($side->other()->unreadColumn());

            return $message->load('attachments');
        });
    }

    /**
     * Mark what the other side wrote as read, and zero this side's
     * counter.
     *
     * Only the other side's messages: a sender does not "read" their
     * own, and stamping them would make read receipts meaningless.
     */
    public function markRead(Conversation $conversation, User $reader): void
    {
        $side = $conversation->sideFor($reader);

        if ($side === null) {
            return;
        }

        DB::transaction(function () use ($conversation, $side): void {
            $conversation->messages()
                ->where('sender_role', $side->other()->value)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $conversation->forceFill([$side->unreadColumn() => 0])->save();
        });
    }

    /**
     * Everything this account has not read, across every thread.
     *
     * TWO AGGREGATES, NOT A LOOP. The header asks for this on every
     * page load. Fetching the threads and summing them in PHP would
     * also mean reading each one's resort to find out which side the
     * account is on — a lazy load per row, which Model::shouldBeStrict
     * rightly throws on, and an N+1 if it did not.
     *
     * An account can be both: an owner who books a stay elsewhere has
     * unread messages on both sides, so the two are added.
     */
    public function unreadCountFor(User $user): int
    {
        /*
         * ONE QUERY. This runs on every page an account loads, through
         * the navbar composer, so it is the cheapest thing it can be:
         * a single aggregate with a join, no models hydrated and no
         * second round trip.
         *
         * An account can be on both sides — an owner who books a stay
         * somewhere else — so each row contributes whichever counter
         * belongs to the side this account is on, and both if both.
         */
        $id = (int) $user->id;

        return (int) Conversation::query()
            ->leftJoin('resorts', 'resorts.id', '=', 'conversations.resort_id')
            ->where(function ($where) use ($id): void {
                $where->where('conversations.tourist_id', $id)
                    ->orWhere('resorts.owner_id', $id);
            })
            ->selectRaw(
                'COALESCE(SUM('
                .'CASE WHEN conversations.tourist_id = ? THEN conversations.tourist_unread ELSE 0 END'
                .' + CASE WHEN resorts.owner_id = ? THEN conversations.owner_unread ELSE 0 END'
                .'), 0) AS waiting',
                [$id, $id]
            )
            ->value('waiting');
    }

    /**
     * Store one picture against a message.
     *
     * THE PRIVATE DISK. On the public one the web server hands the
     * file to anyone holding the URL, with no session and no check —
     * and these are photographs guests send a business. Served only
     * through MessageAttachmentController, which authorises first.
     */
    private function attach(Message $message, UploadedFile $file): void
    {
        $path = $file->store(self::FOLDER.'/'.$message->conversation_id, self::DISK);

        $message->attachments()->create([
            'path' => $path,

            // Kept for display only. Never used as a path: a filename
            // is chosen by the sender, and a path should not be.
            'original_name' => Str::limit($file->getClientOriginalName(), 170, ''),

            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
        ]);
    }
}
