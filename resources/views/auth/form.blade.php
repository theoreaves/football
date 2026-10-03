<x-layouts.app>
<div class="max-w-md mx-auto p-8 text-white">
    <h1 class="text-2xl mb-6">{{ $register ? 'Create your account' : 'Football login' }}</h1>
    @foreach ($errors->all() as $error)<p class="text-red-400">{{ $error }}</p>@endforeach
    <form method="POST" action="{{ $register ? url('/register') : url('/login') }}" class="space-y-4">
        @csrf
        @if ($register)<label class="block">Name<input class="block w-full text-black" name="name" value="{{ old('name') }}" required autocomplete="name"></label>@endif
        <label class="block">Email<input class="block w-full text-black" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
        <label class="block">Password<input class="block w-full text-black" type="password" name="password" required autocomplete="{{ $register ? 'new-password' : 'current-password' }}"></label>
        @if ($register)<label class="block">Confirm password<input class="block w-full text-black" type="password" name="password_confirmation" required autocomplete="new-password"></label>@endif
        <button class="px-4 py-2 bg-blue-700 rounded">{{ $register ? 'Register' : 'Log in' }}</button>
    </form>
    <a class="block mt-4 underline" href="{{ $register ? route('login') : route('register') }}">{{ $register ? 'Already have an account? Log in' : 'Create an account' }}</a>
</div>
</x-layouts.app>
