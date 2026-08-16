<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordConfirmationResponse as FailedPasswordConfirmationResponseContract;

class FailedPasswordConfirmationResponse implements FailedPasswordConfirmationResponseContract
{
    public function toResponse($request)
    {
        $message = 'La contraseña ingresada es incorrecta.';

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                'password' => [$message],
            ]);
        }

        return back()->withErrors(['password' => $message]);
    }
}
