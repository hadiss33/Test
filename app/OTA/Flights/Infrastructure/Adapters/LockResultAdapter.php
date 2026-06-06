<?php

namespace App\Services\OTA\Flights\Infrastructure\Adapters;

use App\Services\OTA\Flights\Application\DTOs\LockFlightResult;

/**
 * تبدیل LockFlightResult DTO به فرمت آرایه‌ای V1
 *
 * V1 رفتار دقیق (از sendRequestFlight شاخه else):
 *
 *   موفق:
 *   [
 *     'Data' => [[
 *       'Status' => true,
 *       'Result' => $raw,   ← کل پاسخ خام Sepehr
 *     ]]
 *   ]
 *   caller خودش $result['Data'][0]['Result']['LockId'] می‌کشه
 *
 *   خطا HTTP/ProviderException:
 *   [
 *     'Data' => [[
 *       'Status'  => false,
 *       'Code'    => '2003-{code}',
 *       'Message' => '...',
 *       'Trace'   => [...],
 *     ]]
 *   ]
 *
 *   خطا Sepehr (ErrorMessage در raw):
 *   raw داخل Result موجوده و caller باید خودش چک کنه
 *   ولی ما اینجا normalize می‌کنیم
 */
class LockResultAdapter
{
    /**
     * دقیقاً مثل V1 — کل raw را wrap می‌کنه
     *
     * موفق:
     *   ['Data' => [['Status' => true, 'Result' => $raw]]]
     *
     * خطا:
     *   ['Data' => [['Status' => false, 'Code' => '...', 'Message' => '...', 'Trace' => [...]]]]
     */
    public static function toV1(LockFlightResult $result): array
    {
        if ($result->status) {
            return [
                'Data' => [[
                    'Status' => true,
                    'Result' => $result->rawResult,
                ]],
            ];
        }

        return [
            'Data' => [[
                'Status'  => false,
                'Code'    => $result->errorCode   ?: '1502',
                'Message' => $result->errorMessage ?: 'خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                'Trace'   => [],
            ]],
        ];
    }

    /**
     * نسخه extracted — LockId مستقیم یا false
     * برای callerهایی که فقط LockId می‌خوان و خودشان error handle می‌کنن
     *
     * string|false
     */
    public static function extractLockId(LockFlightResult $result): string|false
    {
        return $result->status ? $result->lockId : false;
    }

    /**
     * فرمت flat normalized — برای internal use
     */
    public static function toArray(LockFlightResult $result): array
    {
        return [
            'Status'  => $result->status,
            'LockId'  => $result->lockId,
            'Code'    => $result->errorCode,
            'Message' => $result->errorMessage,
            'Result'  => $result->rawResult,
        ];
    }
}