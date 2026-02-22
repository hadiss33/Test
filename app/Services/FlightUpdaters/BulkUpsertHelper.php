<?php

namespace App\Services\FlightUpdaters;

use App\Models\FlightBaggage;
use App\Models\FlightFareBreakdown;
use App\Models\FlightRule;
use App\Models\FlightTax;
use Illuminate\Support\Facades\DB;

trait BulkUpsertHelper
{
    protected function bulkUpsertBreakdown(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $classIds = array_column($rows, 'flight_class_id');

        $existing = FlightFareBreakdown::whereIn('flight_class_id', $classIds)
            ->pluck('id', 'flight_class_id')
            ->all();

        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            $classId = $row['flight_class_id'];
            if (isset($existing[$classId])) {
                $toUpdate[$existing[$classId]] = $row;
            } else {
                $toInsert[] = $row;
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 100) as $batch) {
                FlightFareBreakdown::insert($batch);
            }
        }

        if (! empty($toUpdate)) {
            $this->bulkUpdateById(
                'flight_fare_breakdown',
                $toUpdate,
                ['base_adult', 'base_child', 'base_infant', 'updated_at']
            );
        }
    }

    protected function bulkUpsertBaggage(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $classIds = array_column($rows, 'flight_class_id');

        $existing = FlightBaggage::whereIn('flight_class_id', $classIds)
            ->pluck('id', 'flight_class_id')
            ->all();

        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            $classId = $row['flight_class_id'];
            if (isset($existing[$classId])) {
                $toUpdate[$existing[$classId]] = $row;
            } else {
                $toInsert[] = $row;
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 100) as $batch) {
                FlightBaggage::insert($batch);
            }
        }

        if (! empty($toUpdate)) {
            $this->bulkUpdateById(
                'flight_baggage',
                $toUpdate,
                ['adult_weight', 'adult_pieces', 'child_weight', 'child_pieces', 'infant_weight', 'infant_pieces']
            );
        }
    }

    protected function bulkUpsertTax(array $rows): void
    {
        if (empty($rows)) {
            return;
        }


        $rows = array_filter($rows, function ($row) {
            $hasValue =
                ((float) ($row['YQ'] ?? 0) > 0) ||
                ((float) ($row['HL'] ?? 0) > 0) ||
                ((float) ($row['I6'] ?? 0) > 0) ||
                ((float) ($row['LP'] ?? 0) > 0) ||
                ((float) ($row['V0'] ?? 0) > 0);

            return $hasValue;
        });

        if (empty($rows)) {
            return;
        }

        $classIds = array_unique(array_column($rows, 'flight_class_id'));

        $existing = FlightTax::whereIn('flight_class_id', $classIds)
            ->get()
            ->keyBy(fn ($t) => $t->flight_class_id.'|'.$t->passenger_type)
            ->all();

        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            $key = $row['flight_class_id'].'|'.$row['passenger_type'];
            if (isset($existing[$key])) {
                $toUpdate[$existing[$key]->id] = $row;
            } else {
                $toInsert[] = $row;
            }
        }

        if (! empty($toInsert)) {
            foreach (array_chunk($toInsert, 100) as $batch) {
                FlightTax::insert($batch);
            }
        }

        if (! empty($toUpdate)) {
            $this->bulkUpdateById(
                'flight_taxes',
                $toUpdate,
                ['YQ', 'HL', 'I6', 'LP', 'V0']
            );
        }
    }


    protected function bulkReplaceRules(array $deleteClassIds, array $insertRows): void
    {
        if (! empty($deleteClassIds)) {
            FlightRule::whereIn('flight_class_id', $deleteClassIds)->delete();
        }

        if (! empty($insertRows)) {
            foreach (array_chunk($insertRows, 100) as $batch) {
                FlightRule::insert($batch);
            }
        }
    }

    /**
     * Bulk UPDATE با CASE WHEN - یک query برای همه
     *
     * @param  string  $table  نام جدول
     * @param  array  $toUpdate  [id => row_data]
     * @param  array  $columns  ستون‌هایی که باید آپدیت بشن
     */
    protected function bulkUpdateById(string $table, array $toUpdate, array $columns): void
    {
        if (empty($toUpdate)) {
            return;
        }

        $ids = array_keys($toUpdate);
        $cases = [];
        $params = [];

        foreach ($columns as $col) {
            $caseStr = 'CASE id';
            foreach ($toUpdate as $id => $row) {
                if (array_key_exists($col, $row)) {
                    $caseStr .= ' WHEN ? THEN ?';
                    $params[] = $id;
                    $params[] = $row[$col];
                }
            }
            $caseStr .= ' END';
            $cases[] = "`{$col}` = {$caseStr}";
        }

        if (empty($cases)) {
            return;
        }

        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE `{$table}` SET ".implode(', ', $cases)." WHERE id IN ({$idPlaceholders})";

        DB::statement($sql, array_merge($params, $ids));
    }
}
