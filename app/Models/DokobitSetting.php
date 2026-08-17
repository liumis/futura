<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DokobitSetting extends Model
{
    public const ENVIRONMENT_LIVE = 'live';

    public const ENVIRONMENT_PROD = 'prod';

    public const DEFAULT_LIVE_API_URL = 'https://gateway-sandbox.dokobit.com';

    public const DEFAULT_PROD_API_URL = 'https://gateway.dokobit.com';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'active_environment',
        'live_access_token',
        'live_api_url',
        'prod_access_token',
        'prod_api_url',
    ];

    public static function instance(): self
    {
        return static::query()->firstOrCreate([], [
            'active_environment' => self::ENVIRONMENT_LIVE,
            'live_api_url' => self::DEFAULT_LIVE_API_URL,
            'prod_api_url' => self::DEFAULT_PROD_API_URL,
        ]);
    }

    public function isProductionActive(): bool
    {
        return $this->active_environment === self::ENVIRONMENT_PROD;
    }

    public function activeAccessToken(): ?string
    {
        return $this->isProductionActive()
            ? $this->prod_access_token
            : $this->live_access_token;
    }

    public function activeApiUrl(): string
    {
        if ($this->isProductionActive()) {
            return filled($this->prod_api_url)
                ? (string) $this->prod_api_url
                : self::DEFAULT_PROD_API_URL;
        }

        return filled($this->live_api_url)
            ? (string) $this->live_api_url
            : self::DEFAULT_LIVE_API_URL;
    }
}
