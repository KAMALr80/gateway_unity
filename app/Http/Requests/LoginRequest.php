<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // An email, a WooCommerce username or an ERP contact code; the app sends any of them here.
            'email'    => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}