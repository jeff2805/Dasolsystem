<?php

namespace Tests\Feature\Messaging;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Resort;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A guest and a resort, in private.
 *
 * THE RULE THIS FILE EXISTS TO HOLD: two parties and nobody else.
 * The guest who opened the thread and whoever owns the resort now.
 * Not other guests, not other owners, and — by instruction — not the
 * tourism office, which is the one place this project's usual
 * "super admin may see everything" does not apply.
 *
 * The second rule is the pictures. They go on the PRIVATE disk and
 * are served only through a controller that authorises first. This
 * project has already shipped the other shape once, with GCash
 * proofs on the public disk behind a careful check that guarded
 * nothing, so it is tested here rather than trusted.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private User $owner;

    private Resort $resort;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tourist = User::factory()->create();
        $this->owner = User::factory()->resortOwner()->create();
        $this->resort = Resort::factory()->for($this->owner, 'owner')->active()->create();
    }

    private function thread(): Conversation
    {
        $this->actingAs($this->tourist)
            ->post(route('messages.start', $this->resort))
            ->assertRedirect();

        return Conversation::sole();
    }

    /* Opening a thread ------------------------------------------------------ */

    public function test_a_guest_opens_a_conversation_with_a_resort(): void
    {
        $this->actingAs($this->tourist)
            ->post(route('messages.start', $this->resort))
            ->assertRedirect();

        $conversation = Conversation::sole();

        $this->assertSame((int) $this->tourist->id, (int) $conversation->tourist_id);
        $this->assertSame((int) $this->resort->id, (int) $conversation->resort_id);

        // Opening one is recorded; what is said in it never is.
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'conversation.opened',
            'user_id' => $this->tourist->id,
        ]);
    }

    /**
     * ONE THREAD PER PAIR. Pressing the button twice must land in the
     * same conversation, or the two halves of it never meet.
     */
    public function test_opening_it_twice_returns_the_same_thread(): void
    {
        $first = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.start', $this->resort))
            ->assertRedirect(route('messages.show', $first));

        $this->assertSame(1, Conversation::count());
    }

    public function test_a_resort_owner_cannot_start_a_thread_with_a_guest(): void
    {
        // An owner replies to a guest who wrote first. The portal is
        // not the thing that enables unsolicited contact.
        $this->actingAs($this->owner)
            ->post(route('messages.start', $this->resort))
            ->assertForbidden();

        $this->assertSame(0, Conversation::count());
    }

    public function test_a_hidden_resort_is_not_open_for_enquiries(): void
    {
        $pending = Resort::factory()->for($this->owner, 'owner')->create();

        $this->actingAs($this->tourist)
            ->post(route('messages.start', $pending))
            ->assertForbidden();
    }

    /* Saying something ------------------------------------------------------ */

    public function test_a_guest_writes_and_the_owner_reads_and_replies(): void
    {
        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), ['body' => 'Is the cottage free on Saturday?'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $conversation->refresh();

        // The inbox row and the OTHER side's counter.
        $this->assertSame('Is the cottage free on Saturday?', $conversation->last_message_preview);
        $this->assertSame(1, (int) $conversation->owner_unread);
        $this->assertSame(0, (int) $conversation->tourist_unread);

        // Opening it is reading it.
        $this->actingAs($this->owner)->get(route('messages.show', $conversation))->assertOk();

        $conversation->refresh();
        $this->assertSame(0, (int) $conversation->owner_unread);
        $this->assertNotNull(Message::sole()->read_at);

        // And the reply raises the guest's counter instead.
        $this->actingAs($this->owner)
            ->post(route('messages.store', $conversation), ['body' => 'Yes, it is free.'])
            ->assertSessionHasNoErrors();

        $conversation->refresh();
        $this->assertSame(1, (int) $conversation->tourist_unread);
        $this->assertSame(0, (int) $conversation->owner_unread);
    }

    /** Reading your own message is not reading it. */
    public function test_a_sender_does_not_mark_their_own_message_read(): void
    {
        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), ['body' => 'Hello']);

        $this->actingAs($this->tourist)->get(route('messages.show', $conversation))->assertOk();

        $this->assertNull(Message::sole()->read_at, 'A read receipt must mean the other side saw it.');
    }

    public function test_an_empty_message_is_refused(): void
    {
        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Message::count());
    }

    /* Who may read it -------------------------------------------------------- */

    public function test_another_guest_cannot_open_the_thread(): void
    {
        $conversation = $this->thread();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('messages.show', $conversation))->assertForbidden();
        $this->actingAs($stranger)
            ->post(route('messages.store', $conversation), ['body' => 'Hello'])
            ->assertForbidden();
    }

    public function test_another_resort_owner_cannot_open_the_thread(): void
    {
        $conversation = $this->thread();

        $otherOwner = User::factory()->resortOwner()->create();
        Resort::factory()->for($otherOwner, 'owner')->active()->create();

        $this->actingAs($otherOwner)->get(route('messages.show', $conversation))->assertForbidden();
    }

    /**
     * THE OFFICE IS NOT A PARTY. Every other policy here lets a super
     * admin through; private correspondence is the exception, and it
     * is deliberate.
     */
    public function test_the_tourism_office_cannot_read_a_private_conversation(): void
    {
        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), ['body' => 'Something private']);

        $mto = User::factory()->superAdmin()->create();

        $this->actingAs($mto)->get(route('messages.show', $conversation))->assertForbidden();

        // And nothing of it reaches their inbox either.
        $this->actingAs($mto)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertDontSee('Something private');
    }

    public function test_a_guest_who_is_not_signed_in_is_sent_to_sign_in(): void
    {
        $conversation = $this->thread();

        auth()->logout();

        $this->get(route('messages.index'))->assertRedirect(route('login'));
        $this->get(route('messages.show', $conversation))->assertRedirect(route('login'));
    }

    /** An inbox shows only your own threads. */
    public function test_the_inbox_lists_only_your_own_conversations(): void
    {
        $mine = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $mine), ['body' => 'My own enquiry']);

        $stranger = User::factory()->create();
        $otherOwner = User::factory()->resortOwner()->create();
        $otherResort = Resort::factory()->for($otherOwner, 'owner')->active()->create();

        $this->actingAs($stranger)->post(route('messages.start', $otherResort));
        $theirs = Conversation::query()->where('tourist_id', $stranger->id)->sole();

        $this->actingAs($stranger)
            ->post(route('messages.store', $theirs), ['body' => 'Somebody elses enquiry']);

        $this->actingAs($this->tourist)
            ->get(route('messages.index'))
            ->assertOk()
            ->assertSee('My own enquiry')
            ->assertDontSee('Somebody elses enquiry');
    }

    /* Pictures ---------------------------------------------------------------- */

    /**
     * ON THE PRIVATE DISK, and reachable only through the controller.
     */
    public function test_a_picture_is_stored_privately_and_served_only_to_the_two_parties(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), [
                'body' => 'Here is the receipt',
                'attachments' => [UploadedFile::fake()->image('receipt.png')],
            ])
            ->assertSessionHasNoErrors();

        $attachment = \App\Models\MessageAttachment::sole();

        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);

        // Both parties may see it.
        $this->actingAs($this->tourist)->get(route('messages.attachment', $attachment))->assertOk();
        $this->actingAs($this->owner)->get(route('messages.attachment', $attachment))->assertOk();

        // Nobody else, including the office.
        $this->actingAs(User::factory()->create())
            ->get(route('messages.attachment', $attachment))->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('messages.attachment', $attachment))->assertForbidden();

        auth()->logout();
        $this->get(route('messages.attachment', $attachment))->assertRedirect(route('login'));
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        Storage::fake('local');

        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), [
                'attachments' => [UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf')],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, Message::count());
    }

    /* Booking context ---------------------------------------------------------- */

    public function test_a_thread_opened_from_a_reservation_carries_it(): void
    {
        $room = Room::factory()->for($this->resort)->create();

        $booking = Booking::factory()
            ->for($this->resort)->for($room)
            ->for($this->tourist, 'tourist')
            ->create();

        $this->actingAs($this->tourist)
            ->post(route('messages.start', $this->resort), ['booking' => $booking->id])
            ->assertRedirect();

        $conversation = Conversation::sole();

        $this->assertSame((int) $booking->id, (int) $conversation->booking_id);

        $this->actingAs($this->owner)
            ->get(route('messages.show', $conversation))
            ->assertOk()
            ->assertSee($booking->booking_reference);
    }

    /**
     * A guessed id must not attach somebody else's reservation, which
     * would show its reference and dates to an owner who has no claim
     * on them.
     */
    public function test_another_guests_booking_cannot_be_attached(): void
    {
        $room = Room::factory()->for($this->resort)->create();

        $theirs = Booking::factory()
            ->for($this->resort)->for($room)
            ->for(User::factory()->create(), 'tourist')
            ->create();

        $this->actingAs($this->tourist)
            ->post(route('messages.start', $this->resort), ['booking' => $theirs->id])
            ->assertRedirect();

        $this->assertNull(Conversation::sole()->booking_id);
    }

    /* The header badge ---------------------------------------------------------- */

    public function test_the_header_shows_what_is_waiting(): void
    {
        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), ['body' => 'A question']);

        // The owner has one waiting, and the page says so without JavaScript.
        $this->actingAs($this->owner)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('vd-msg-link__count', false)
            ->assertSee('Messages, 1 unread', false);

        $this->actingAs($this->owner)
            ->getJson(route('messages.unread-count'))
            ->assertOk()
            ->assertJson(['unread' => 1]);
    }

    /** The polling endpoint gives a number and nothing else. */
    public function test_the_unread_endpoint_leaks_no_content(): void
    {
        $conversation = $this->thread();

        $this->actingAs($this->tourist)
            ->post(route('messages.store', $conversation), ['body' => 'Secret plans']);

        $response = $this->actingAs($this->owner)
            ->getJson(route('messages.unread-count'))
            ->assertOk();

        $this->assertSame(['unread'], array_keys($response->json()));
        $response->assertDontSee('Secret plans');
    }

    public function test_a_signed_out_visitor_gets_no_badge(): void
    {
        $this->get(route('home'))->assertOk()->assertDontSee('vd-msg-link', false);
    }
}
