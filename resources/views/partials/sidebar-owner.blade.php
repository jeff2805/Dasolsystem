{{-- Resort owner navigation --}}
<ul class="vd-sidebar__list">
    <x-sidebar-link route="owner.dashboard" icon="speedometer2">
        Dashboard
    </x-sidebar-link>
</ul>

<p class="vd-sidebar__group">My resort</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link
        route="owner.resort.show"
        match="owner.resort.*"
        icon="house-heart">
        Resort profile
    </x-sidebar-link>

    <x-sidebar-link
        route="owner.rooms.index"
        match="owner.rooms.*"
        icon="door-open">
        Rooms
    </x-sidebar-link>

    {{-- Kayak hire, a banana boat ride, island hopping. Beside Rooms
         because it is the other half of what a guest is choosing. --}}
    <x-sidebar-link
        route="owner.extras.index"
        match="owner.extras.*"
        icon="water">
        Extras
    </x-sidebar-link>

    {{-- The shared list every resort picks from. Here rather than under
         Account because it is about the listing, not the person. --}}
    <x-sidebar-link
        route="owner.amenities.index"
        match="owner.amenities.*"
        icon="stars">
        Amenities
    </x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Guests</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link
        route="owner.bookings.index"
        match="owner.bookings.*"
        icon="calendar-check">
        Reservations
    </x-sidebar-link>

    {{-- Guests who have written in. Under Guests rather than Account
         because it is about the people staying, not about the owner's
         own settings. --}}
    <x-sidebar-link
        route="messages.index"
        match="messages.*"
        icon="chat-dots"
        :count="$unreadMessages ?? null">
        Messages
    </x-sidebar-link>

    <x-sidebar-link route="owner.availability" icon="calendar3">
        Availability
    </x-sidebar-link>

    <x-sidebar-link
        route="owner.reviews.index"
        match="owner.reviews.*"
        icon="chat-quote">
        Guest reviews
    </x-sidebar-link>

    <x-sidebar-link route="owner.statistics" icon="bar-chart-line">
        Statistics
    </x-sidebar-link>
</ul>

<p class="vd-sidebar__group">Account</p>
<ul class="vd-sidebar__list">
    <x-sidebar-link route="profile.edit" icon="person-circle">
        Profile
    </x-sidebar-link>

    {{-- What this resort asks for up front, and by when. --}}
    <x-sidebar-link route="owner.payment-policy.edit" match="owner.payment-policy.*" icon="receipt">
        Payment terms
    </x-sidebar-link>

    {{-- GCash Payment Settings --}}
    <li>
        <a
            href="{{ route('owner.manual-gcash.edit') }}"
            class="vd-sidebar__link {{ request()->routeIs('owner.manual-gcash.*') ? 'active' : '' }}"
            @if (request()->routeIs('owner.manual-gcash.*'))
                aria-current="page"
            @endif
        >
            <i class="bi bi-qr-code" aria-hidden="true"></i>
            <span>GCash Payment Settings</span>
        </a>
    </li>

    {{-- Sign out --}}
    @if (Route::has('logout'))
        <li>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="vd-sidebar__link">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span>Sign out</span>
                </button>
            </form>
        </li>
    @endif
</ul>