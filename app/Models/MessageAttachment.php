<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture sent with a message.
 *
 * THE PATH IS ON THE PRIVATE DISK AND THERE IS NO url() HERE, on
 * purpose. A helper returning Storage::url() would be the one line
 * that puts a guest's photograph on the open web, and it would look
 * entirely reasonable in a Blade. The only way to read one of these
 * is MessageAttachmentController, which authorises first.
 */
class MessageAttachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** What to call it on screen, falling back to something harmless. */
    public function displayName(): string
    {
        return $this->original_name ?: 'Attachment';
    }

    public function readableSize(): string
    {
        $kb = $this->size_bytes / 1024;

        return $kb < 1024
            ? number_format($kb).' KB'
            : number_format($kb / 1024, 1).' MB';
    }
}
