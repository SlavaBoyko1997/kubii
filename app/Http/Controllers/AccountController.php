<?php

namespace App\Http\Controllers;

use App\Rules\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.index', [
            'orders' => $request->user()
                ->orders()
                ->latest()
                ->orderByDesc('id')
                ->with('items.product')
                ->paginate(5)
                ->withQueryString(),
        ]);
    }

    public function storePhone(Request $request): RedirectResponse
    {
        if ($request->user()->phone) {
            return back()->with('error', __('Телефон вже вказано у вашому профілі.'));
        }

        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone'))]);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30', new PhoneNumber, Rule::unique('users', 'phone')],
        ]);

        $request->user()->update(['phone' => $validated['phone']]);

        return back()->with('success', __('Номер телефону додано до профілю.'));
    }

    public function storeProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $rules = [
            'last_name' => $user->last_name ? ['nullable'] : ['required', 'string', 'max:80'],
            'first_name' => $user->first_name ? ['nullable'] : ['required', 'string', 'max:80'],
            'patronymic' => $user->patronymic ? ['nullable'] : ['nullable', 'string', 'max:80'],
        ];

        $validated = $request->validate($rules);
        $updates = collect($validated)
            ->filter(fn (mixed $value, string $key): bool => blank($user->{$key}) && filled($value))
            ->all();

        if ($updates === []) {
            return back()->with('error', __('Немає нових даних для збереження.'));
        }

        $updates['name'] = collect([
            $updates['last_name'] ?? $user->last_name,
            $updates['first_name'] ?? $user->first_name,
            $updates['patronymic'] ?? $user->patronymic,
        ])->filter()->implode(' ');

        $user->update($updates);

        return back()->with('success', __('Дані профілю оновлено.'));
    }
}
