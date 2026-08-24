<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Promo\Enums\PromoType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(['admin', 'superadmin']) ?? false;
    }

    public function rules(): array
    {
        return [
            'promo_code' => ['nullable', 'string', 'max:50', 'unique:promos,promo_code,'.$this->promo?->id],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', Rule::in(collect(PromoType::cases())->pluck('value')->toArray())],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'trigger_quantity' => ['nullable', 'integer', 'min:1'],
            'free_quantity' => ['nullable', 'integer', 'min:0'],
            'free_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'free_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'free_item_name' => ['nullable', 'string', 'max:200'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
