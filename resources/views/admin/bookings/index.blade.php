@extends('layouts.admin')

@section('title', 'Bookings')

@section('content')
    <x-page-header title="Reservations"
                   subtitle="Every reservation across all resorts. Resorts answer their own requests; this screen is for monitoring." />

    <ul class="nav nav-pills mb-3 gap-1">
        <li class="nav-item">
            <a class="nav-link @if (! $filters['status']) active @endif" href="{{ route('admin.bookings.index') }}">All</a>
        </li>
        @foreach (\App\Enums\BookingStatus::cases() as $case)
            <li class="nav-item">
                <a class="nav-link @if ($filters['status'] === $case) active @endif"
                   href="{{ route('admin.bookings.index', ['status' => $case->value]) }}">
                    {{ $case->label() }}
                    <span class="badge rounded-pill text-bg-light ms-1">{{ $counts[$case->value] ?? 0 }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <form method="GET" action="{{ route('admin.bookings.index') }}" class="vd-panel p-3 mb-3">
        @if ($filters['status'])
            <input type="hidden" name="status" value="{{ $filters['status']->value }}">
        @endif

        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="filter-q">Search</label>
                <input type="search" class="form-control" id="filter-q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Reference, guest, or email">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="filter-resort">Resort</label>
                <select class="form-select" id="filter-resort" name="resort">
                    <option value="">All resorts</option>
                    @foreach ($resorts as $id => $name)
                        <option value="{{ $id }}" @selected($filters['resort']?->id === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-basis">Dates refer to</label>
                <select class="form-select" id="filter-basis" name="basis">
                    <option value="stay" @selected($filters['basis'] === 'stay')>The stay</option>
                    <option value="requested" @selected($filters['basis'] === 'requested')>When requested</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-from">From</label>
                <input type="date" class="form-control" id="filter-from" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="filter-to">To</label>
                <input type="date" class="form-control" id="filter-to" name="to" value="{{ $filters['to'] }}">
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a class="btn btn-light border" href="{{ route('admin.bookings.index') }}">Clear</a>
        </div>
    </form>

    @if ($bookings->isEmpty())
        <x-empty-state icon="journal-check" title="No reservations match"
                       message="Try a wider date range, another resort, or clear the filters." />
    @else
        <p class="text-muted-vd">
            {{ $bookings->total() }} {{ Str::plural('reservation', $bookings->total()) }} found.
            Showing {{ $bookings->count() }} on this page, {{ $totalNights }} {{ Str::plural('night', $totalNights) }} in total.
        </p>

        <x-data-table label="Reservations" class="text-nowrap">
            <x-slot:head>
                <tr>
                    {{-- This cell carries the reference and the date the request
                         came in, so it offers both orderings. --}}
                    <x-sort-header :sort="$sort" column="requested">Reference</x-sort-header>
                    {{-- Resort and guest both live on other tables, reached by
                         eager loading rather than a join, so there is no column
                         on this query to order them by. --}}
                    <th scope="col">Resort</th>
                    <th scope="col">Guest</th>
                    <x-sort-header :sort="$sort" column="check_in">Stay</x-sort-header>
                    <x-sort-header :sort="$sort" column="status">Status</x-sort-header>
                    <th scope="col" class="text-end">Action</th>
                </tr>
            </x-slot:head>

            @foreach ($bookings as $booking)
                <tr>
                    <td class="font-monospace small text-nowrap">
                        {{ $booking->booking_reference }}
                        <span class="d-block text-muted-vd">{{ $booking->created_at->format('M j, Y') }}</span>
                    </td>
                    <td>
                        <span class="d-block">{{ $booking->resort?->name }}</span>
                        <span class="small text-muted-vd">{{ $booking->room?->name }}</span>
                    </td>
                    <td>
                        <span class="d-block">{{ $booking->contact_name }}</span>
                        <span class="small text-muted-vd">{{ $booking->guests }} {{ Str::plural('guest', $booking->guests) }}</span>
                    </td>
                    <td class="text-nowrap">
                        {{ $booking->dateRangeLabel() }}
                        <span class="d-block small text-muted-vd">{{ $booking->durationLabel() }}</span>
                    </td>
                    <td><x-status-badge :status="$booking->status" /></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.bookings.show', $booking) }}">View</a>
                    </td>
                </tr>
            @endforeach

            <x-slot:below>{{ $bookings->links() }}</x-slot:below>
        </x-data-table>
    @endif
@endsection
