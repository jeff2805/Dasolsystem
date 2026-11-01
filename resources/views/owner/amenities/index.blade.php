@extends('layouts.owner')

@section('title', 'My resort amenities')

@section('content')
    <x-page-header title="My resort amenities"
                   subtitle="Manage only the amenities offered by {{ $resort->name }}. Changes here do not affect other resorts.">
        <a class="btn btn-light border" href="{{ route('owner.resort.edit') }}">Back to my resort</a>
    </x-page-header>

    @if (session('success'))
        <div class="alert alert-success" role="status">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>Please check the form.</strong>
            <ul class="mb-0 mt-2">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="vd-panel">
                <div class="p-3 border-bottom">
                    <h2 class="h5 mb-1">Amenities on my resort</h2>
                    <p class="small text-muted-vd mb-0">Edit, hide, or delete your own entries at any time.</p>
                </div>
                <x-scroll-region label="My resort amenities">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Amenity</th>
                                <th scope="col">Visibility</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($amenities as $amenity)
                                <tr>
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <span class="vd-feature__icon" style="width:2.25rem;height:2.25rem;font-size:1rem">
                                                <i class="bi bi-{{ $amenity->icon ?? 'check-lg' }}" aria-hidden="true"></i>
                                            </span>
                                            <span>{{ $amenity->name }}</span>
                                        </span>
                                    </td>
                                    <td>
                                        @if ($amenity->is_active)
                                            <span class="vd-badge vd-badge--success">Visible</span>
                                        @else
                                            <span class="vd-badge">Hidden</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex flex-wrap justify-content-end gap-2">
                                            <button class="btn btn-sm btn-outline-primary" type="button"
                                                    data-bs-toggle="collapse" data-bs-target="#edit-amenity-{{ $amenity->id }}"
                                                    aria-expanded="false" aria-controls="edit-amenity-{{ $amenity->id }}">
                                                Edit
                                            </button>
                                            <form method="POST" action="{{ route('owner.amenities.toggle', $amenity) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                    {{ $amenity->is_active ? 'Disable' : 'Enable' }}
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('owner.amenities.destroy', $amenity) }}"
                                                  onsubmit="return confirm('Delete this amenity from your resort?')">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="collapse" id="edit-amenity-{{ $amenity->id }}">
                                    <td colspan="3">
                                        <form method="POST" action="{{ route('owner.amenities.update', $amenity) }}"
                                              class="row g-2 align-items-end">
                                            @csrf
                                            @method('PUT')
                                            <div class="col-md-6">
                                                <label class="form-label" for="name-{{ $amenity->id }}">Amenity name</label>
                                                <input class="form-control" id="name-{{ $amenity->id }}" name="name"
                                                       value="{{ $amenity->name }}" maxlength="80" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="icon-{{ $amenity->id }}">Icon name</label>
                                                <input class="form-control" id="icon-{{ $amenity->id }}" name="icon"
                                                       value="{{ $amenity->icon }}" maxlength="50">
                                            </div>
                                            <div class="col-md-2">
                                                <button class="btn btn-primary w-100" type="submit">Save</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted-vd py-4">
                                        No amenities yet. Add the amenities your resort offers.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-scroll-region>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="vd-panel p-4">
                <h2 class="h5 mb-3">Add an amenity</h2>
                <form method="POST" action="{{ route('owner.amenities.store') }}">
                    @csrf
                    <x-form.input name="name" label="Amenity name" required
                                  :value="old('name')"
                                  help="Example: Kayak rental, Beach volleyball, or Bonfire area." />
                    <x-form.input name="icon" label="Icon name" :value="old('icon')"
                                  help="Optional Bootstrap Icons name, such as wifi, cup-hot, or water." />
                    <button type="submit" class="btn btn-primary">Add amenity</button>
                </form>
            </section>

            <aside class="vd-panel p-4 mt-4">
                <h2 class="h6 mb-2">Your list is private to your resort</h2>
                <p class="small text-muted-vd mb-0">
                    Other resort owners cannot edit or delete these amenities. Disabling an amenity
                    hides it from your public resort page and visitor filters without deleting it.
                    Deleting removes only your resort's entry.
                </p>
            </aside>
        </div>
    </div>
@endsection
