<x-layouts.app title="Login · Event Lead Capture">
    <div class="hero-row">
        <div>
            <h1>Internal Login</h1>
            <p class="subhead">Capture conference contacts, confirm district fit, and send reviewed records to HubSpot.</p>
        </div>
    </div>

    <section class="panel" style="max-width: 440px;">
        <form method="post" action="{{ route('login.store') }}" class="stack">
            @csrf
            <div>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <label style="display:flex;align-items:center;gap:9px;text-transform:none;letter-spacing:0;font-size:14px;color:var(--body);font-weight:700;">
                <input type="checkbox" name="remember" value="1" style="width:auto;">
                Keep me signed in
            </label>
            <button class="button accent" type="submit">Log in</button>
        </form>
    </section>
</x-layouts.app>
