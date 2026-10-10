<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'currency'              => ['sometimes', 'string', 'size:3'],
            'discount_total'        => ['sometimes', 'numeric', 'min:0'],
            'shipping_total'        => ['sometimes', 'numeric', 'min:0'],
            'total_tax'             => ['sometimes', 'numeric', 'min:0'],
            'customer_note'         => ['sometimes', 'string', 'nullable'],
            'payment_method'        => ['sometimes', 'string'],
            'meta_data'             => ['sometimes', 'array'],

            // Billing
            'billing_first_name'    => ['required', 'string', 'max:100'],
            'billing_last_name'     => ['required', 'string', 'max:100'],
            'billing_company'       => ['sometimes', 'string', 'nullable'],
            'billing_address_1'     => ['required', 'string', 'max:255'],
            'billing_address_2'     => ['sometimes', 'string', 'nullable'],
            'billing_city'          => ['required', 'string', 'max:100'],
            'billing_state'         => ['sometimes', 'string', 'nullable'],
            'billing_postcode'      => ['required', 'string', 'max:20'],
            'billing_country'       => ['required', 'string', 'size:2'],
            'billing_email'         => ['required', 'email'],
            'billing_phone'         => ['required', 'string', 'max:20'],

            // Shipping
            'shipping_first_name'   => ['required', 'string', 'max:100'],
            'shipping_last_name'    => ['required', 'string', 'max:100'],
            'shipping_company'      => ['sometimes', 'string', 'nullable'],
            'shipping_address_1'    => ['required', 'string', 'max:255'],
            'shipping_address_2'    => ['sometimes', 'string', 'nullable'],
            'shipping_city'         => ['required', 'string', 'max:100'],
            'shipping_state'        => ['sometimes', 'string', 'nullable'],
            'shipping_postcode'     => ['required', 'string', 'max:20'],
            'shipping_country'      => ['required', 'string', 'size:2'],

            // Line items
            'items'                         => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id'            => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity'              => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}