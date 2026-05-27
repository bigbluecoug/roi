<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::attempt($credentials, $request->boolean('remember')) && ! $this->attemptInternalLogin($request, $credentials)) {
            return back()
                ->withErrors(['email' => 'Those credentials do not match an internal user.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('setup.state'));
    }

    private function attemptInternalLogin(Request $request, array $credentials): bool
    {
        $internalUser = collect(config('internal-users.users', []))
            ->first(fn (array $user): bool => Str::lower($user['email'] ?? '') === $credentials['email']);

        if (! $internalUser || ! $this->internalPasswordMatches($credentials['password'])) {
            return false;
        }

        $user = User::updateOrCreate([
            'email' => $credentials['email'],
        ], [
            'name' => $internalUser['name'],
            'password' => config('internal-users.password', 'capture'),
        ]);

        Auth::login($user, $request->boolean('remember'));

        return true;
    }

    private function internalPasswordMatches(string $password): bool
    {
        return collect([
            config('internal-users.password', 'capture'),
            config('internal-users.additional_password'),
        ])
            ->filter()
            ->unique()
            ->contains(fn (string $validPassword): bool => hash_equals($validPassword, $password));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
