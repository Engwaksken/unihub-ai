<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.show', ['user' => $request->user()]);
    }

    /**
     * Shows the current recovery codes (password confirmation required).
     */
    public function recoveryCodes(Request $request): View
    {
        return view('account.recovery-codes', ['codes' => $request->user()->recoveryCodes()]);
    }
}
