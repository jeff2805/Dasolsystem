<?php

namespace App\Http\Controllers;

use App\Http\Requests\MessageRequest;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Resort;
use App\Services\MessagingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Conversations, for both sides.
 *
 * ONE CONTROLLER, NOT TWO. A guest and an owner do exactly the same
 * four things — list, open, reply, mark read — and the only
 * difference is which side of the thread they are on, which
 * Conversation::sideFor() already answers. Two controllers would be
 * two copies of the same authorisation, and the day they drift is the
 * day one of them leaks.
 *
 * Every action authorises against ConversationPolicy, which admits
 * the guest who opened the thread and the current owner of the
 * resort, and nobody else — not other guests, not other owners, and
 * not the tourism office.
 */
class ConversationController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    /** The inbox. Whichever side this account is on. */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $user = $request->user();
        $search = trim((string) $request->query('q', ''));

        $conversations = Conversation::query()
            ->forParticipant($user)
            ->with([
                'resort:id,name,slug,barangay,main_image,owner_id',
                'tourist:id,first_name,last_name',
                'booking:id,booking_reference,check_in_date,check_out_date,status',
            ])
            /*
             * Searching looks at the other party's name and the last
             * thing said. Never at every message: a thread search that
             * reads bodies would need an index over private
             * correspondence, and the inbox is for finding a
             * conversation, not for finding a sentence inside one.
             */
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($where) use ($search): void {
                    $where->whereHas('resort', fn ($r) => $r->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('tourist', function ($t) use ($search): void {
                            $t->where('first_name', 'like', '%'.$search.'%')
                                ->orWhere('last_name', 'like', '%'.$search.'%');
                        })
                        ->orWhere('last_message_preview', 'like', '%'.$search.'%');
                });
            })
            ->recent()
            ->paginate(20)
            ->withQueryString();

        return view('messages.index', [
            'conversations' => $conversations,
            'search' => $search,
            'unread' => $this->messaging->unreadCountFor($user),
        ]);
    }

    /** One thread, and everything said in it. */
    public function show(Request $request, Conversation $conversation): View
    {
        $this->authorize('view', $conversation);

        $user = $request->user();

        // Opening a thread is reading it.
        $this->messaging->markRead($conversation, $user);

        $conversation->load([
            'resort:id,name,slug,barangay,main_image,owner_id,contact_number',
            'tourist:id,first_name,last_name',
            'booking:id,booking_reference,check_in_date,check_out_date,status,total_amount,rooms_booked',
        ]);

        return view('messages.show', [
            'conversation' => $conversation,
            'side' => $conversation->sideFor($user),

            'messages' => $conversation->messages()
                ->with(['attachments', 'sender:id,first_name,last_name'])
                ->orderBy('id')
                ->get(),
        ]);
    }

    /** Say something. */
    public function store(MessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('reply', $conversation);

        try {
            $this->messaging->send(
                $conversation,
                $request->user(),
                $request->body(),
                $request->pictures()
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        /*
         * Back to the thread, which scrolls to the newest message.
         * Redirect rather than render, so a refresh does not send the
         * message twice.
         */
        return redirect()
            ->route('messages.show', $conversation)
            ->withFragment('newest');
    }

    /**
     * Open — or reopen — the thread with a resort, and go to it.
     *
     * Reached from the resort page and from a reservation. POST
     * because it can create a row.
     */
    public function start(Request $request, Resort $resort): RedirectResponse
    {
        /*
         * Conversation::class FIRST, then the resort.
         *
         * Policies are auto-discovered from the argument's model, so
         * authorize('start', $resort) looks in ResortPolicy — which has
         * no start() and therefore refuses everything. Naming the class
         * sends it to ConversationPolicy, where the ability belongs,
         * and the resort arrives as the second parameter.
         */
        $this->authorize('start', [Conversation::class, $resort]);

        $booking = null;

        if ($request->filled('booking')) {
            /*
             * Context has to be the asker's own reservation at this
             * resort. Without both checks, a guessed id would attach
             * somebody else's booking to a thread and show its
             * reference and dates to the owner.
             */
            $booking = Booking::query()
                ->where('id', $request->input('booking'))
                ->where('user_id', $request->user()->id)
                ->where('resort_id', $resort->id)
                ->first();
        }

        $conversation = $this->messaging->between($request->user(), $resort, $booking);

        return redirect()->route('messages.show', $conversation);
    }

    /**
     * What the header badge asks for.
     *
     * Polling, because Reverb is not installed here. Deliberately thin:
     * a number and nothing else. No names, no previews, nothing that
     * would put a fragment of a private conversation into a response
     * that is fetched every few seconds.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        return response()->json([
            'unread' => $this->messaging->unreadCountFor($request->user()),
        ]);
    }
}
