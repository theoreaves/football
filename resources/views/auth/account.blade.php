<x-layouts.app :immersive="true">
<div class="min-h-screen flex items-center justify-center p-6 text-white">
    <section class="w-full max-w-md rounded-2xl border border-gray-700 bg-gray-800 p-8 space-y-5">
        <a href="{{ route('login') }}" class="text-blue-300 font-semibold">Football</a>
        <h1 class="text-3xl font-semibold">{{ match($screen) { 'register' => 'Create your account', 'password.request' => 'Forgot your password?', 'password.reset' => 'Reset your password', 'verification.notice' => 'Verify your email', default => 'Welcome back' } }}</h1>
        @if(session('status'))<p role="status" class="rounded bg-blue-950 p-3 text-blue-200">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded bg-red-950 p-3 text-red-200">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if($screen === 'verification.notice')
            <p class="text-gray-300">We sent a verification link to {{ auth()->user()->email }}. Open it to start creating your leagues.</p>
            @if(config('services.google.client_id') && config('services.google.client_secret'))<a href="{{ route('google.redirect') }}" class="block border rounded p-3 text-center">Verify and link with Google</a>@endif
            <form method="POST" action="{{ route('verification.send') }}">@csrf<button class="w-full rounded bg-blue-600 py-3">Resend verification email</button></form>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-gray-300 underline">Sign out</button></form>
        @else
            @if(in_array($screen, ['login', 'register']) && config('services.google.client_id') && config('services.google.client_secret'))
                <a href="{{ route('google.redirect') }}" class="block rounded border border-gray-500 bg-white text-gray-900 p-3 text-center font-semibold">Continue with Google</a>
                <p class="text-sm text-gray-400 text-center">or use your email</p>
            @endif
            <form method="POST" action="{{ route(match($screen) { 'register' => 'register.store', 'password.request' => 'password.email', 'password.reset' => 'password.update', default => 'login.store' }) }}" class="space-y-4">
                @csrf
                @if($screen === 'password.reset')<input type="hidden" name="token" value="{{ request()->route('token') }}">@endif
                @if($screen === 'register')<label class="block">Name<input name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="255" class="block w-full mt-1 rounded border border-gray-600 bg-gray-900 p-3 text-white"></label>@endif
                <label class="block">Email<input type="email" name="email" value="{{ old('email', request('email')) }}" autocomplete="email" required maxlength="255" class="block w-full mt-1 rounded border border-gray-600 bg-gray-900 p-3 text-white"></label>
                @if($screen !== 'password.request')
                    <label class="block">Password<input type="password" name="password" autocomplete="{{ $screen === 'login' ? 'current-password' : 'new-password' }}" required @if($screen !== 'login') minlength="12" @endif class="block w-full mt-1 rounded border border-gray-600 bg-gray-900 p-3 text-white"></label>
                    @if($screen !== 'login')<p class="text-sm text-gray-400">Use at least 12 characters.</p><label class="block">Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required class="block w-full mt-1 rounded border border-gray-600 bg-gray-900 p-3 text-white"></label>@else<label class="flex gap-2 items-center"><input type="checkbox" name="remember" value="1">Remember me</label>@endif
                @endif
                <button class="w-full rounded bg-blue-600 py-3 font-semibold">{{ match($screen) { 'register' => 'Create account', 'password.request' => 'Send reset link', 'password.reset' => 'Reset password', default => 'Sign in' } }}</button>
            </form>
            <div class="flex flex-wrap gap-4 text-sm text-blue-300">
                @if($screen === 'login')<a href="{{ route('register') }}" class="underline">Create an account</a><a href="{{ route('password.request') }}" class="underline">Forgot password?</a>@else<a href="{{ route('login') }}" class="underline">Back to sign in</a>@endif
            </div>
        @endif
    </section>
</div>
</x-layouts.app>
