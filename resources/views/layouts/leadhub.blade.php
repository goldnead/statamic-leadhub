@extends('statamic::layout')

@section('content')
    @if (session('success'))
        <div class="px-2 py-2 rounded bg-green-100 text-green-700 mb-4">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="px-2 py-2 rounded bg-red-100 text-red-700 mb-4">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('leadhub-content')
@endsection
