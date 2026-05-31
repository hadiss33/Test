<?php

namespace App\Services\OTA\Flights\Infrastructure\Providers\Sepehr\V2;

use Illuminate\Support\Facades\DB;

final class Auth
{
    /** @var array<int, array> */
    private static array $cache = [];

    public static function getSuppliers(string|int|false $branch): array
    {
        if (!$branch) {
            throw new \InvalidArgumentException('Branch is required');
        }

        $cacheKey = is_string($branch) ? $branch : (string) $branch;
        
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $suppliers = DB::table('application_interface')
            ->where('object_type', 'colleague')
            ->where(function ($q) use ($branch) {
                $q->where('branch', $branch);
            })
            ->where('status', 1)
            ->where('type', 'api')
            ->where('service', 'sepehr')
            ->get();

        if ($suppliers->isEmpty()) {
            // Hub fallback
            if (str_contains((string)$branch, 'b2c') || str_contains((string)$branch, 'b2b')) {
                $branchId = explode('-', (string)$branch)[1] ?? $branch;
            } else {
                $branchId = $branch;
            }

            $tempBranch = DB::table('offices')
                ->select('type', 'base_online')
                ->where('id', $branchId)
                ->first();

            if ($tempBranch && $tempBranch->base_online == 1) {
                $suppliers = DB::table('application_interface')
                    ->where('branch', 1)
                    ->where('object_type', 'colleague')
                    ->where('status', 1)
                    ->where('type', 'api')
                    ->where('service', 'sepehr')
                    ->get();

                if ($suppliers->isEmpty()) {
                    throw new \RuntimeException('Api Not Found!');
                }
            } else {
                throw new \RuntimeException('Api Not Found!');
            }
        }

        $result = [];
        foreach ($suppliers as $supplier) {
            $data = !is_null($supplier->data) ? json_decode($supplier->data, true) : [];
            
            $result[] = [
                'id' => $supplier->id,
                'object' => $supplier->object,
                'branch' => $supplier->branch,
                'url' => $supplier->url,
                'username' => $supplier->username,
                'password' => $supplier->password,
                'nira' => $data['nira'] ?? false,
                'isHub' => $supplier->branch == 1,
            ];
        }

        self::$cache[$cacheKey] = $result;
        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}