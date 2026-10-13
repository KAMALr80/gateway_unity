<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Product extends Model
{
    use HasSlug;

    protected $fillable = [
        'erp_product_id',
        'parent_id',
        'category_id',
        'sub_category_id',
        'brand_id',
        'name',
        'slug',
        'website_url',
        'type',
        'description',
        'short_description',
        'sku',
        'regular_price',
        'sale_price',
        'manage_stock',
        'stock_quantity',
        'in_stock',
        'is_active',
        'attributes',
        'synced_at',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    protected $casts = [
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'manage_stock' => 'boolean',
        'in_stock' => 'boolean',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'attributes' => 'array',
        'synced_at' => 'datetime',
    ];

    // Current active price — sale price if set, otherwise regular price
    public function getCurrentPriceAttribute(): string
    {
        return $this->sale_price ?? $this->regular_price;
    }

    public function isOnSale(): bool
    {
        return ! is_null($this->sale_price) && $this->sale_price < $this->regular_price;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id')
            ->where('is_active', true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'sub_category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('sort_order');
    }

    public function primaryImage(): MorphOne
    {
        return $this->morphOne(Media::class, 'mediable')->where('is_primary', true);
    }
}
