<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\Resort;
use App\Models\User;

/**
 * Who may read and write a private conversation.
 *
 * TWO PARTIES, AND NOBODY ELSE. The guest who opened it and whoever
 * owns the resort at the time of asking. That is the whole rule, and
 * every method below is a restatement of it.
 *
 * THE OFFICE IS NOT A PARTY, deliberately and by instruction. Every
 * other policy in this project lets a super admin through, because
 * the municipality has a legitimate interest in listings, bookings
 * and money. Private correspondence between a guest and a business is
 * a different kind of thing, and a tourism portal that reads it is
 * not one people should use. There is no admin screen for this and no
 * super-admin branch here — if a complaint ever needs investigating,
 * that is a decision for a person with a lawful reason, made outside
 * this application.
 *
 * Ownership is read from the resort rather than stored on the thread,
 * so a listing that changes hands takes its conversations with it.
 */
class ConversationPolicy
{
    /** Anyone signed in has an inbox; it may be empty. */
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $user->isActive() && $conversation->includes($user);
    }

    /**
     * Writing into a thread is the same right as reading it.
     *
     * Separate from view() all the same: they are different questions
     * and a future rule — a thread closed after a stay, say — would
     * change one and not the other.
     */
    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    /**
     * Starting a thread with a resort.
     *
     * The guest's side only. An owner messaging a tourist out of the
     * blue is not a conversation, it is unsolicited contact, and the
     * portal should not be the thing that enables it — an owner
     * replies to a guest who wrote first.
     *
     * The resort has to be one a visitor could have found: an
     * unapproved or hidden listing is not open for enquiries.
     */
    public function start(User $user, Resort $resort): bool
    {
        return $user->isActive()
            && $user->isTourist()
            && $resort->isPubliclyVisible();
    }
}
