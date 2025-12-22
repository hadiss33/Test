<?php

namespace App\Repositories\ServiceRepositories;

use App\Models\ApplicationInterface;
use App\Repositories\Contracts\FlightServiceRepositoryInterface;
use Illuminate\Support\Facades\Cache;

class FlightServiceRepository implements FlightServiceRepositoryInterface
{
    protected $model;

    public function __construct(ApplicationInterface $model)
    {
        $this->model = $model;
    }


    public function getActiveServices(string $serviceName): array
    {
        return Cache::remember("flight_services_{$serviceName}", 300, function () use ($serviceName) {
            return $this->model
                ->active()
                ->byType('api')
                ->byService($serviceName)
                ->get()
                ->map(fn($item) => $this->transformToConfig($item, $serviceName))
                ->filter(fn($item) => $item['code'] !== null)
                ->values()
                ->toArray();
        });
    }


    public function getServiceByCode(string $serviceName, string $code): ?array
    {
        $item = $this->model
            ->active()
            ->byType('api')
            ->byService($serviceName)
            ->get()
            ->first(function ($item) use ($code) {
                return isset($item->data['iata']) && $item->data['iata'] === $code;
            });

        return $item ? $this->transformToConfig($item, $serviceName) : null;
    }


    public function isServiceActive(string $serviceName, string $code): bool
    {
        return $this->model
            ->active()
            ->byType('api')
            ->byService($serviceName)
            ->get()
            ->contains(function ($item) use ($code) {
                return isset($item->data['iata']) && $item->data['iata'] === $code;
            });
    }


    protected function transformToConfig($item, string $serviceName): array
    {
        if ($serviceName === 'nira') {
            return [
                'id' => $item->id,
                'code' => $item->data['iata'] ?? null,
                'name' => $this->getAirlineName($item->data['iata'] ?? null),
                'base_url_ws1' => $item->url,
                'base_url_ws2' => $item->data['url'] ?? null,
                'office_user' => $item->username,
                'office_pass' => $item->password,
                'service' => $serviceName,
            ];
        }

        if ($serviceName === 'snapptrip_hotel') {
            return [
                'id' => $item->id,
                'code' => $item->data['provider_code'] ?? null,
                'name' => $item->data['provider_name'] ?? 'Unknown',
                'base_url' => $item->url,
                'api_key' => $item->username,
                'api_secret' => $item->password,
                'service' => $serviceName,
            ];
        }

        return [
            'id' => $item->id,
            'code' => null,
            'service' => $serviceName,
        ];
    }


    protected function getAirlineName(?string $iataCode): string
    {
        $airlines = [
            'Y9' => 'کیش ایر',
            'EP' => 'ایران ایر',
            'FP' => 'فلای پرشیا',
            'HH' => 'تابان',
            'IV' => 'کاسپین',
            'I3' => 'آتا',
            'IRZ' => 'سها',
            'J1' => 'معراج',
            'NV' => 'کارون',
            'PA' => 'پارس ایر',
            'QB' => 'قشم ایر',
            'VR' => 'وارش',
            'ZV' => 'زاگرس',
        ];

        return $airlines[$iataCode] ?? "Airline {$iataCode}";
    }


    public function clearCache(string $serviceName): void
    {
        Cache::forget("flight_services_{$serviceName}");
    }
}