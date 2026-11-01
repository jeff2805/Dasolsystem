@extends('layouts.public')

@section('title', 'Messages')

@push('styles')
    <link rel="stylesheet" href="@vdasset('css/messages.css')">
@endpush

@push('scripts')
    <script src="@vdasset('js/messages.js')"></script>
@endpush

@php
    $isOwner = $side?->value === 'owner';

    $otherName = $isOwner
        ? ($conversation->tourist?->full_name ?? 'Guest')
        : $conversation->resort->name;
@endphp

@section('content')
    <div class="container py-5">
        <x-page-header :title="$otherName"
                       :subtitle="$isOwner ? 'Guest enquiry' : 'Brgy. '.$conversation->resort->barangay">
            <a class="btn btn-light border" href="{{ route('messages.index') }}">All messages</a>

            @unless ($isOwner)
                <a class="btn btn-outline-primary"
                   href="{{ route('resorts.show', $conversation->resort) }}">View resort</a>
            @endunless
        </x-page-header>

        @if (session('error'))
            <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
        @endif

        <div class="row g-4">
            <div class="col-lg-8">
                {{-- The thread. Oldest at the top, which is how a
                     conversation reads, with the newest anchored so the
                     page lands on it after a reply. --}}
                <section class="vd-thread" data-thread>
                    @forelse ($messages as $message)
                        @php($ours = $message->wasSentBy(auth()->user()))

                        <article @class(['vd-bubble', 'vd-bubble--ours' => $ours])
                                 @if ($loop->last) id="newest" @endif>
                            <header class="vd-bubble__head">
                                <span class="vd-bubble__who">
                                    {{ $ours ? 'You' : ($message->sender?->first_name ?? 'Account removed') }}
                                </span>

                                <time class="vd-bubble__when"
                                      datetime="{{ $message->created_at->toIso8601String() }}">
                                    {{ $message->created_at->format('M j, g:i A') }}
                                </time>
                            </header>

                            @if (filled($message->body))
                                <p class="vd-bubble__body">{{ $message->body }}</p>
                            @endif

                            @foreach ($message->attachments as $attachment)
                                {{-- Served through a controller that authorises
                                     first. The files are on the private disk and
                                     have no public URL at all. --}}
                                {{-- x-external-link, not a bare target="_blank".
                                     The component appends the "opens in a new
                                     tab" note every other such link on this
                                     site carries, which UiConsistencyTest
                                     requires and a screen reader needs. --}}
                                <x-external-link class="vd-bubble__picture"
                                                 :href="route('messages.attachment', $attachment)">
                                    <img src="{{ route('messages.attachment', $attachment) }}"
                                         alt="{{ $attachment->displayName() }}" loading="lazy">
                                </x-external-link>
                            @endforeach

                            {{-- A read receipt belongs to what you sent, not
                                 to what you received. --}}
                            @if ($ours)
                                <p class="vd-bubble__receipt">
                                    @if ($message->isRead())
                                        <i class="bi bi-check2-all" aria-hidden="true"></i> Read
                                    @else
                                        <i class="bi bi-check2" aria-hidden="true"></i> Sent
                                    @endif
                                </p>
                            @endif
                        </article>
                    @empty
                        <p class="vd-thread__empty">
                            No messages yet. Say hello below.
                        </p>
                    @endforelse
                </section>

                <form class="vd-reply" method="POST"
                      action="{{ route('messages.store', $conversation) }}"
                      enctype="multipart/form-data" data-submit-once>
                    @csrf

                    <label class="visually-hidden" for="message-body">Your message</label>

                    <textarea class="form-control @error('body') is-invalid @enderror"
                              id="message-body" name="body" rows="3" maxlength="2000"
                              placeholder="Write a message…">{{ old('body') }}</textarea>

                    @error('body')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                    <div class="vd-reply__foot">
                        <label class="vd-reply__attach" for="message-attachments">
                            <i class="bi bi-image me-1" aria-hidden="true"></i>Add pictures
                            <input class="visually-hidden" type="file" id="message-attachments"
                                   name="attachments[]" multiple
                                   accept="image/jpeg,image/png,image/webp">
                        </label>

                        <span class="vd-reply__chosen" data-attachment-count aria-live="polite"></span>

                        <button class="btn btn-accent ms-auto" type="submit">
                            <i class="bi bi-send me-1" aria-hidden="true"></i>Send
                        </button>
                    </div>

                    @error('attachments')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    @foreach ($errors->get('attachments.*') as $messages)
                        @foreach ($messages as $message)
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @endforeach
                    @endforeach

                    <p class="form-text mb-0">
                        Up to four pictures, 5 MB each. JPG, PNG or WEBP.
                    </p>
                </form>
            </div>

            <div class="col-lg-4">
                {{-- Who you are talking to. --}}
                <aside class="vd-panel p-4 mb-4">
                    <h2 class="h6 mb-2">{{ $isOwner ? 'The guest' : 'The resort' }}</h2>

                    @if ($isOwner)
                        <p class="mb-0">{{ $conversation->tourist?->full_name ?? 'Account removed' }}</p>
                    @else
                        <p class="mb-1">{{ $conversation->resort->name }}</p>
                        <p class="small text-muted-vd mb-0">
                            Brgy. {{ $conversation->resort->barangay }}
                            @if (filled($conversation->resort->contact_number))
                                <span class="d-block">{{ $conversation->resort->contact_number }}</span>
                            @endif
                        </p>
                    @endif
                </aside>

                {{-- The stay being discussed, when the thread was opened
                     from one. Only what both parties already know: the
                     reference, the dates and the state of it. --}}
                @if ($conversation->booking)
                    <aside class="vd-panel p-4">
                        <h2 class="h6 mb-2">About this reservation</h2>

                        <dl class="row small mb-0">
                            <dt class="col-5 text-muted-vd fw-normal">Reference</dt>
                            <dd class="col-7 font-monospace">{{ $conversation->booking->booking_reference }}</dd>

                            <dt class="col-5 text-muted-vd fw-normal">Dates</dt>
                            <dd class="col-7">
                                {{ $conversation->booking->check_in_date->format('M j') }}
                                to {{ $conversation->booking->check_out_date->format('M j, Y') }}
                            </dd>

                            <dt class="col-5 text-muted-vd fw-normal">Status</dt>
                            <dd class="col-7 mb-0">
                                <x-status-badge :status="$conversation->booking->status" />
                            </dd>
                        </dl>

                        <a class="btn btn-sm btn-light border mt-3"
                           href="{{ $isOwner
                                ? route('owner.bookings.show', $conversation->booking)
                                : route('tourist.bookings.show', $conversation->booking) }}">
                            Open the reservation
                        </a>
                    </aside>
                @endif
            </div>
        </div>
    </div>
@endsection
