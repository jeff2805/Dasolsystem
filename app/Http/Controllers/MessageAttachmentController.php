<?php

namespace App\Http\Controllers;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving a picture somebody sent in a conversation.
 *
 * THE ONLY WAY TO READ ONE. The files are on the private disk, which
 * the web server does not publish, so this action is the whole of the
 * access control — and it authorises against the conversation the
 * picture belongs to before it reads a byte.
 *
 * That ordering is the point. This project has already shipped the
 * other shape once: GCash proofs were written to the public disk
 * while a careful ownership check guarded the controller beside them,
 * so the check protected a door that stood open. Private file, then
 * authorised reader. Never the other way round.
 */
class MessageAttachmentController extends Controller
{
    private const DISK = 'local';

    public function __invoke(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $conversation = $attachment->message?->conversation;

        abort_if($conversation === null, 404);

        // The same rule as reading the thread: a picture is part of it.
        $this->authorize('view', $conversation);

        // A row whose file has gone is a 404, not a 500.
        abort_if(blank($attachment->path), 404);
        abort_unless(Storage::disk(self::DISK)->exists($attachment->path), 404);

        /*
         * Shown in the page, not downloaded, and under a name this
         * application chose. The sender's own filename is displayed in
         * the thread but never used here: it is attacker-controlled
         * text, and a Content-Disposition is the wrong place for it.
         */
        return Storage::disk(self::DISK)->response(
            $attachment->path,
            'attachment-'.$attachment->id,
            [
                'Content-Type' => $attachment->mime_type,

                // Private to these two people; no shared cache may keep it.
                'Cache-Control' => 'private, max-age=0, no-store',

                // Belt and braces against a crafted image being
                // interpreted as something else.
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
