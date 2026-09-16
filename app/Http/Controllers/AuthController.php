<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PhoneNumber;
use App\Support\Cart;
use App\Support\GuestCustomerProfile;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse|JsonResponse
    {
        $cart = app(Cart::class);
        $request->merge([
            'login' => trim((string) ($request->input('login') ?: $request->input('email'))),
        ]);
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ], [
            'login.required' => __('Вкажіть email або номер телефону.'),
        ]);

        $user = $this->findUserForLogin($validated['login']);
        $credentials = $user
            ? ['email' => $user->email, 'password' => $validated['password']]
            : ['email' => '__missing_user__', 'password' => $validated['password']];

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $message = __('Невірний email, номер телефону або пароль.');

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['login' => [$message]],
                ], 422);
            }

            return back()->withErrors(['login' => $message])->onlyInput('login');
        }

        $request->session()->regenerate();
        $destination = $this->resolvePostAuthDestination($request, $cart);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Ви увійшли до кабінету.'),
                'redirect' => $destination,
            ]);
        }

        return redirect()->to($destination)->with('success', __('Ви увійшли до кабінету.'));
    }

    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl($this->googleCallbackUrl())
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')
                ->redirectUrl($this->googleCallbackUrl())
                ->user();
        } catch (Throwable) {
            return redirect()
                ->to(localized_route('login'))
                ->withErrors(['login' => __('Не вдалося увійти через Google. Спробуйте ще раз.')]);
        }

        $email = mb_strtolower(trim((string) $googleUser->getEmail()));
        $googleId = trim((string) $googleUser->getId());

        if ($email === '' || $googleId === '') {
            return redirect()
                ->to(localized_route('login'))
                ->withErrors(['login' => __('Google не надав обовʼязкові дані для входу.')]);
        }

        $nameParts = $this->extractNameParts($googleUser);
        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $nameParts['name'],
                'last_name' => $nameParts['last_name'],
                'first_name' => $nameParts['first_name'],
                'patronymic' => null,
                'email' => $email,
                'phone' => null,
                'password' => Str::random(64),
                'google_id' => $googleId,
                'google_avatar' => $googleUser->getAvatar(),
                'email_verified_at' => now(),
            ]);
        } else {
            $updates = [
                'google_id' => $googleId,
                'google_avatar' => $googleUser->getAvatar(),
            ];

            if (! $user->email_verified_at) {
                $updates['email_verified_at'] = now();
            }

            if (blank($user->first_name) && $nameParts['first_name']) {
                $updates['first_name'] = $nameParts['first_name'];
            }

            if (blank($user->last_name) && $nameParts['last_name']) {
                $updates['last_name'] = $nameParts['last_name'];
            }

            if (blank($user->name) && $nameParts['name']) {
                $updates['name'] = $nameParts['name'];
            }

            $user->update($updates);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $destination = $this->resolvePostAuthDestination($request, app(Cart::class));

        return redirect()
            ->to($destination)
            ->with('success', __('Ви успішно увійшли через Google.'));
    }

    private function findUserForLogin(string $login): ?User
    {
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($login)])
                ->first();
        }

        $digits = preg_replace('/\D+/', '', $login);

        if ($digits === '') {
            return null;
        }

        $phones = [PhoneNumber::normalize($login), $digits, '+'.$digits];

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $phones = [...$phones, '+38'.$digits, '38'.$digits];
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '380')) {
            $phones = [...$phones, '+'.$digits, substr($digits, 2)];
        } elseif (strlen($digits) === 9) {
            $phones = [...$phones, '+380'.$digits, '380'.$digits, '0'.$digits];
        }

        return User::query()->whereIn('phone', array_values(array_unique($phones)))->first();
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone'))]);
        }

        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:80'],
            'first_name' => ['required', 'string', 'max:80'],
            'patronymic' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->where(fn ($query) => $query->where('is_guest', false))],
            'phone' => ['required', 'string', 'max:30', new PhoneNumber, Rule::unique(User::class, 'phone')->where(fn ($query) => $query->where('is_guest', false))],
            'password' => ['required', 'confirmed', Password::min(8)->max(255)],
        ], [
            'last_name.required' => __('Вкажіть прізвище.'),
            'first_name.required' => __('Вкажіть ім’я.'),
            'patronymic.required' => __('Вкажіть по батькові.'),
            'email.required' => __('Вкажіть email.'),
            'email.email' => __('Вкажіть коректний email.'),
            'email.unique' => __('Клієнт із таким email уже зареєстрований.'),
            'phone.required' => __('Вкажіть номер телефону.'),
            'phone.unique' => __('Клієнт із таким номером телефону вже зареєстрований.'),
            'password.required' => __('Вкажіть пароль.'),
            'password.confirmed' => __('Паролі не збігаються.'),
            'password.min' => __('Пароль має містити щонайменше 8 символів.'),
        ]);

        $validated['name'] = collect([$validated['last_name'], $validated['first_name'], $validated['patronymic'] ?? null])->filter()->implode(' ');
        $profile = app(GuestCustomerProfile::class);
        $guest = User::query()
            ->where('is_guest', true)
            ->where(function ($query) use ($validated): void {
                $query->whereRaw('LOWER(email) = ?', [mb_strtolower($validated['email'])])
                    ->orWhere('phone', $validated['phone']);
            })
            ->first();

        if ($guest) {
            $user = $profile->upgradeGuestToRegistered($guest, [
                'name' => $validated['name'],
                'last_name' => $validated['last_name'],
                'first_name' => $validated['first_name'],
                'patronymic' => $validated['patronymic'],
                'email' => mb_strtolower($validated['email']),
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
            ]);
        } else {
            $validated['email'] = mb_strtolower($validated['email']);
            $validated['password'] = Hash::make($validated['password']);
            $user = User::create($validated);
        }
        Auth::login($user);
        $request->session()->regenerate();
        app(Cart::class)->moveGuestToAccount();
        $destination = $request->session()->pull('url.intended', localized_route('account.index'));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Профіль успішно створено.'),
                'redirect' => $destination,
            ]);
        }

        return redirect()->to($destination)->with('success', __('Реєстрацію завершено. Вітаємо у Kubii!'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(localized_route('home'))->with('success', __('Ви вийшли з кабінету.'));
    }

    private function googleCallbackUrl(): string
    {
        $path = Locale::route('auth.google.callback', [], false, Locale::DEFAULT);

        if (app()->environment('local')) {
            return rtrim((string) config('app.url'), '/').$path;
        }

        return url($path);
    }

    private function resolvePostAuthDestination(Request $request, Cart $cart): string
    {
        $guestCart = $cart->guestContents();
        $destination = $request->session()->pull('url.intended', localized_route('account.index'));
        $accountCart = $cart->accountContents();

        if ($guestCart !== [] && $accountCart !== []) {
            $request->session()->put([
                'cart_merge_pending' => $guestCart,
                'cart_merge_destination' => $destination,
            ]);
            $cart->clearGuest();

            return localized_route('cart.merge.show');
        }

        if ($guestCart !== []) {
            $cart->replaceAccount($guestCart);
            $cart->clearGuest();
        }

        return $destination;
    }

    /**
     * @return array{name:string,last_name:?string,first_name:?string}
     */
    private function extractNameParts(SocialiteUser $googleUser): array
    {
        $firstName = trim((string) data_get($googleUser->user, 'given_name', ''));
        $lastName = trim((string) data_get($googleUser->user, 'family_name', ''));
        $name = trim((string) ($googleUser->getName() ?: ''));

        if ($firstName === '' && $name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $firstName = trim((string) ($parts[0] ?? ''));
            $lastName = trim((string) implode(' ', array_slice($parts, 1)));
        }

        $fullName = trim(implode(' ', array_filter([$lastName, $firstName])));

        return [
            'name' => $fullName !== '' ? $fullName : $name,
            'last_name' => $lastName !== '' ? $lastName : null,
            'first_name' => $firstName !== '' ? $firstName : null,
        ];
    }
}
