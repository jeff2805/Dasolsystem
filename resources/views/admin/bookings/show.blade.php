@extends('layouts.admin')

@section('title', $booking->booking_reference)

@section('content')
    <x-page-header :title="$booking->booking_reference" :subtitle="$booking->resort?->name">
        <a class="btn btn-light border" href="{{ route('admin.bookings.index') }}">Back to reservations</a>
    </x-page-header>

    <div class="alert alert-info d-flex gap-2" role="status">
        <i class="bi bi-eye mt-1" aria-hidden="true"></i>
        <div>
            This is a record for monitoring. The resort answers its own requests, and the guest cancels their own
            reservations, so there are no buttons here.
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel p-4 mb-4">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <h2 class="h5 mb-0">The stay</h2>
                    <x-status-badge :status="$booking->status" />
                </div>

                <dl class="row mb-0">
                    <x-stay-dates :booking="$booking" />

                    <dt class="col-sm-4 text-muted-vd fw-normal">Room type</dt>
                    <dd class="col-sm-8">{{ $booking->rooms_booked }} x {{ $booking->room?->name }}</dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Guests</dt>
                    <dd class="col-sm-8"><x-guest-party :booking="$booking" /></dd>

                    <dt class="col-sm-4 text-muted-vd fw-normal">Requested</dt>
                    <dd class="col-sm-8 mb-0">{{ $booking->created_at->format('M j, Y g:i A') }}</dd>
                </dl>

                @if (filled($booking->special_requests))
                    <div class="border-top mt-3 pt-3">
                        <strong class="d-block">Guest note</strong>
                        <span class="text-muted-vd">{{ $booking->special_requests }}</span>
                    </div>
                @endif
            </section>

            {{-- What the guest asked for besides the room. Read-only
                 here: answering is the resort's job, not the office's. --}}
            <x-booking-extras :booking="$booking" />

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">What happened</h2>
                <ul class="list-unstyled mb-0 small">
                    <li class="border-top py-2">
                        <span class="d-block">Requested by {{ $booking->contact_name }}</span>
                        <span class="text-muted-vd">{{ $booking->created_at->format('M j, Y g:i A') }}</span>
                    </li>
                    @if ($booking->confirmed_at)
                        <li class="border-top py-2">
                            <span class="d-block">Confirmed by the resort</span>
                            <span class="text-muted-vd">{{ $booking->confirmed_at->format('M j, Y g:i A') }}</span>
                        </li>
                    @endif
                    @if ($booking->rejected_at)
                        <li class="border-top py-2">
                            <span class="d-block">Declined by the resort</span>
                            <span class="text-muted-vd">
                                {{ $booking->rejected_at->format('M j, Y g:i A') }}
                                @if (filled($booking->owner_remarks)) · {{ $booking->owner_remarks }} @endif
                            </span>
                        </li>
                    @endif
                    @if ($booking->cancelled_at)
                        <li class="border-top py-2">
                            <span class="d-block">
                                Cancelled{{ $booking->cancelledBy ? ' by '.$booking->cancelledBy->full_name : '' }}
                            </span>
                            <span class="text-muted-vd">
                                {{ $booking->cancelled_at->format('M j, Y g:i A') }}
                                @if (filled($booking->cancellation_reason)) · {{ $booking->cancellation_reason }} @endif
                            </span>
                        </li>
                    @endif
                    @if ($booking->completed_at)
                        <li class="border-top py-2">
                            <span class="d-block">Marked completed</span>
                            <span class="text-muted-vd">{{ $booking->completed_at->format('M j, Y g:i A') }}</span>
                        </li>
                    @endif
                </ul>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4 mb-4">
                <h2 class="h5 mb-3">Guest</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted-vd fw-normal">Name on booking</dt>
                    <dd class="col-7">{{ $booking->contact_name }}</dd>

                    <dt class="col-5 text-muted-vd fw-normal">Contact number</dt>
                    <dd class="col-7">{{ $booking->contact_phone }}</dd>

                    @if ($booking->contact_email)
                        <dt class="col-5 text-muted-vd fw-normal">Email for this booking</dt>
                        <dd class="col-7 text-break">{{ $booking->contact_email }}</dd>
                    @endif

                    <dt class="col-5 text-muted-vd fw-normal">Account</dt>
                    <dd class="col-7 mb-0">
                        @if ($booking->tourist)
                            <a href="{{ route('admin.users.show', $booking->tourist) }}">{{ $booking->tourist->full_name }}</a>
                            <span class="d-block text-muted-vd text-break">{{ $booking->tourist->email }}</span>
                        @else
                            <span class="text-muted-vd">Account removed</span>
                        @endif
                    </dd>
                </dl>
            </section>

            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Resort</h2>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted-vd fw-normal">Listing</dt>
                    <dd class="col-7">
                        <a href="{{ route('admin.resorts.show', $booking->resort) }}">{{ $booking->resort?->name }}</a>
                        <span class="d-block"><x-status-badge :status="$booking->resort->status" /></span>
                    </dd>

                    <dt class="col-5 text-muted-vd fw-normal">Owner</dt>
                    <dd class="col-7">
                        <a href="{{ route('admin.users.show', $booking->resort->owner) }}">{{ $booking->resort->owner?->full_name }}</a>
                    </dd>

                    <dt class="col-5 text-muted-vd fw-normal">Contact</dt>
                    <dd class="col-7 mb-0">{{ $booking->resort?->contact_number }}</dd>
                </dl>
            </section>
        </div>
    </div>
@endsection
