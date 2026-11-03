<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()->orderBy('name')->get();

        return view('admin.users', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $request->boolean('is_admin'),
            'is_active' => true,
        ]);

        return back()->with('status', 'کاربر ایجاد شد.');
    }

    public function toggle(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'نمی‌توانید حساب خودتان را غیرفعال کنید.']);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('status', 'وضعیت کاربر به‌روز شد.');
    }
}
