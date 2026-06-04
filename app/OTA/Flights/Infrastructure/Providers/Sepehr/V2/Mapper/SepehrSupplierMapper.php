<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2\Mapper;

use App\Services\OTA\Flights\Infrastructure\Persistence\ApiMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * مسئول resolve کردن supplier و system_supplier برای Sepehr.
 * از mapping_colleagues.sepehr استفاده می‌کند (ستون مختص Sepehr).
 */
class SepehrSupplierMapper
{
    public function __construct(
        private readonly ApiMapper $apiMapper,
    ) {}

    public function getSupplier(int $sepehrId, bool $isHub = false): array
    {
        $mapping = DB::table('mapping_colleagues')
            ->select('colleague')
            ->where('sepehr', $sepehrId)
            ->where('status', 1)
            ->first();

        $colleagueId = ($mapping && !$isHub) ? $mapping->colleague : 1;

        $data            = $this->apiMapper->getColleague($colleagueId);
        $data['base_id'] = $sepehrId;
        return $data;
    }

    public function getSystemSupplier(int $sepehrId): array
    {
        $mapping = DB::table('mapping_colleagues')
            ->select('colleague')
            ->where('sepehr', $sepehrId)
            ->where('status', 1)
            ->first();

        $colleagueId = $mapping?->colleague ?? 0;

        if ($colleagueId === 0) {
            return [
                'id'            => $sepehrId,
                'title_fa'      => $sepehrId,
                'title_en'      => $sepehrId,
                'first_name'    => $sepehrId,
                'last_name'     => $sepehrId,
                'credit_amount' => $sepehrId,
                'status'        => $sepehrId,
                'base_id'       => $sepehrId,
            ];
        }

        $data            = $this->apiMapper->getColleague($colleagueId);
        $data['base_id'] = $sepehrId;

        if (!isset($data['id'])) {
            Redis::delete('colleagues:' . $colleagueId);
        }
        return $data;
    }

    /**
     * برای پروازهای Nira - بر اساس airline IATA کد، تامین اصلی را پیدا می‌کند
     */
    public function resolveNiraSupplier(string $airlineIata, int $fallbackSepehrId): array
    {
        $map = [
            'NV' => 125,  // کارون
            'VR' => 129,  // وارش
            'I3' => 134,  // آتا
            'HH' => 123,  // تابان
            'QB' => 131,  // قشم ایر
            'ZV' => 130,  // زاگرس
        ];

        $id = $map[$airlineIata] ?? $fallbackSepehrId;
        return ['id' => $id];
    }
}