<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();
        $email = strtolower((string) $googleUser->getEmail());

        $allowedDomain = config('services.google.allowed_domain');

        if ($allowedDomain && ! str_ends_with($email, '@' . $allowedDomain)) {
            return redirect(rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . '/');
        }

        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->firstOrFail();

        $user = User::query()
            ->where('email', $email)
            ->first();

        if ($user?->is_archived) {
            return redirect(rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . '/');
        }

        if (! $user) {
            return redirect(
                rtrim(config('app.frontend_url', 'http://localhost:5173'), '/')
                . '/login?error=not_invited'
            );
        }

        $user->forceFill([
                'google_id' => $googleUser->getId(),
                'google_avatar_url' => $googleUser->getAvatar(),
                'avatar_url' => $googleUser->getAvatar(),
                'name' => $googleUser->getName() ?: $user->name,
                'email_verified_at' => $user->email_verified_at ?: now(),
                'last_login_at' => now(),
        ])->save();

        Auth::login($user, true);
        request()->session()->regenerate();

        return redirect(rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . '/');
    }
}
