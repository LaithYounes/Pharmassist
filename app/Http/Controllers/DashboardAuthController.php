<?php

namespace App\Http\Controllers;

use App\Models\Pharmacist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class DashboardAuthController extends Controller
{
    public function form()
    {
        return view('dashboard.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $pharmacist = Pharmacist::where('username', $credentials['username'])->first();
        if (!$pharmacist || !Hash::check($credentials['password'], $pharmacist->password)) {
            return back()->withErrors(['username' => 'Invalid credentials.']);
        }

        Auth::guard('web')->login($pharmacist);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/dashboard/login');
    }
}
