<?php

namespace App\Services\OTA\Flights\Infrastructure\Adapters;

use App\Services\OTA\Flights\Application\DTOs\BookStatusResult;

/**
 * تبدیل BookStatusResult DTO به فرمت آرایه‌ای V1
 *
 * V1 در Book fallback وقتی BookGetStatus صدا می‌زد:
 *
 * حالت ۱ — StatusId != 1 (صادر نشده):
 *   [
 *     'Data' => [[
 *       'Status'  => false,
 *       'Code'    => '1505-{StatusId}',
 *       'Message' => '{LocalPnr}:{StatusDesc}:Last Error:...',
 *     ]]
 *   ]
 *
 * حالت ۲ — ErrorMessage وجود داره:
 *   [
 *     'Data' => [[
 *       'Status'  => false,
 *       'Code'    => '1504-{ExceptionType}',
 *       'Message' => 'ErrorMessage',
 *     ]]
 *   ]
 *
 * حالت ۳ — StatusId == 1 (صادر شده):
 *   [
 *     'Data' => [[
 *       'Status'   => true,
 *       'StatusId' => 1,
 *       'LocalPnr' => '...',
 *     ]]
 *   ]
 */
class BookStatusResultAdapter
{
    /**
     * خروجی کاملاً سازگار با V1
     *
     * @return array
     */
    public static function toV1(BookStatusResult $result): array
    {
        // حالت خطای HTTP / ProviderException
        if (!$result->status && $result->errorCode && str_starts_with($result->errorCode, '2')) {
            return [
                'Data' => [[
                    'Status'  => false,
                    'Code'    => $result->errorCode,
                    'Message' => $result->errorMessage ?: 'خطایی رخ داده است. لطفا به واحد IT اطلاع دهید.',
                ]],
                'Result' => $result->rawResult,
            ];
        }

        // حالت ErrorMessage از Sepehr (1504)
        if (!$result->status && $result->errorCode && str_starts_with($result->errorCode, '1504')) {
            return [
                'Data' => [[
                    'Status'  => false,
                    'Code'    => $result->errorCode,
                    'Message' => $result->errorMessage,
                ]],
                'Result' => $result->rawResult,
            ];
        }

        // حالت StatusId != 1 (1505)
        if (!$result->status && $result->statusId !== false) {
            return [
                'Data' => [[
                    'Status'  => false,
                    'Code'    => '1505-' . $result->statusId,
                    'Message' => ($result->localPnr ?: '')
                        . ':' . ($result->statusDesc ?: '')
                        . ($result->failReason ? ' | ' . $result->failReason : ''),
                ]],
                'Result' => $result->rawResult,
            ];
        }

        // حالت موفق — StatusId == 1
        if ($result->status) {
            return [
                'Data' => [[
                    'Status'     => true,
                    'StatusId'   => $result->statusId,
                    'StatusDesc' => $result->statusDesc,
                    'LocalPnr'   => $result->localPnr,
                ]],
                'Result' => $result->rawResult,
            ];
        }

        // fallback عمومی
        return [
            'Data' => [[
                'Status'  => false,
                'Code'    => $result->errorCode   ?: '1500',
                'Message' => $result->errorMessage ?: 'خطای نامشخص',
            ]],
            'Result' => $result->rawResult,
        ];
    }

    /**
     * فرمت flat برای استفاده مستقیم در controller یا legacy service
     *
     * [
     *   'Status'     => bool,
     *   'StatusId'   => int|false,
     *   'StatusDesc' => string|false,
     *   'LocalPnr'   => string|false,
     *   'FailReason' => string|false,
     *   'Code'       => string|false,
     *   'Message'    => string|false,
     *   'Result'     => array,
     * ]
     */
    public static function toArray(BookStatusResult $result): array
    {
        return [
            'Status'     => $result->status,
            'StatusId'   => $result->statusId,
            'StatusDesc' => $result->statusDesc,
            'LocalPnr'   => $result->localPnr,
            'FailReason' => $result->failReason,
            'Code'       => $result->errorCode,
            'Message'    => $result->errorMessage,
            'Result'     => $result->rawResult,
        ];
    }
}