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
                ->where('url', 'https://sepehrapitest.ir')
                ->get()
                ->map(fn ($item) => $this->transformToConfig($item, $serviceName))

                ->filter(function ($item) use ($serviceName) {
                    if ($serviceName === 'nira') {
                        return $item['code'] !== null;
                    }

                    return true; 
                })
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
                $data = $item->data;

                return isset($data['iata']) && $data['iata'] === $code;
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
                $data = $item->data;

                return isset($data['iata']) && $data['iata'] === $code;
            });
    }

    protected function transformToConfig($item, string $serviceName): array
    {
        $data = $item->data;

        if ($serviceName === 'nira') {
            return [
                'id' => $item->id,
                'code' => $data['iata'] ?? null,
                'name' => $this->getAirlineName($data['iata'] ?? null),
                'base_url_ws1' => $item->url,
                'base_url_ws2' => $data['url'] ?? null,
                'office_user' => $item->username,
                'office_pass' => $item->password,
                'service' => $serviceName,
            ];
        }

        return [
            'id' => $item->id,
            'code' => null,
            'url' => $item->url,
            'username' => $item->username,
            'password' => $item->password,
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

    public function getService(string $serviceName): ?array
    {
        $item = $this->model
            ->active()
            ->where('service', $serviceName)
            ->get()
            ->first();

        return [
            'id' => $item->id,
            'code' => $data['iata'] ?? null,
            'url' => $item->url,
            'username' => $item->username,
            'password' => $item->password,
            'service' => $serviceName,
        ];
    }
}
