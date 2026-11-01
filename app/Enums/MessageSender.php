<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Which side of a conversation wrote a message.
 *
 * Stored on the message rather than worked out from the author's
 * current role, so a thread still reads correctly after an account
 * changes hands or a role changes. Who said what is history, and
 * history should not be recomputed.
 */
enum MessageSender: string
{
    use EnumHelpers;

    case Tourist = 'tourist';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Tourist => 'Guest',
            self::Owner => 'Resort',
        };
    }

    /** The other side, which is whose unread count a message raises. */
    public function other(): self
    {
        return $this === self::Tourist ? self::Owner : self::Tourist;
    }

    /** The conversation column holding this side's unread count. */
    public function unreadColumn(): string
    {
        return $this === self::Tourist ? 'tourist_unread' : 'owner_unread';
    }
}
