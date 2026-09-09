<?php

namespace Tests\Unit\Models;

use Blocked_periods_model;
use Tests\TestCase;

class BlockedPeriodDayCoverageTest extends TestCase
{
    public function testOnlyAWholeDayIntervalTriggersTheFastPath(): void
    {
        $CI = &get_instance();
        $CI->load->model('blocked_periods_model');
        /** @var Blocked_periods_model $model */
        $model = $CI->blocked_periods_model;
        $cases = [
            'single full day' => [[['2035-02-19 00:00:00', '2035-02-20 00:00:00']], true],
            'spanning days' => [[['2035-02-18 12:00:00', '2035-02-21 12:00:00']], true],
            'partial day' => [[['2035-02-19 09:00:00', '2035-02-19 10:00:00']], false],
            'two partial periods with a gap' => [
                [['2035-02-19 09:00:00', '2035-02-19 10:00:00'], ['2035-02-19 11:00:00', '2035-02-19 12:00:00']],
                false,
            ],
            'touching day boundaries' => [
                [['2035-02-18 00:00:00', '2035-02-19 00:00:00'], ['2035-02-20 00:00:00', '2035-02-21 00:00:00']],
                false,
            ],
        ];

        foreach ($cases as $label => [$periods, $expected]) {
            $ids = [];
            try {
                foreach ($periods as [$start, $end]) {
                    $ids[] = $model->save([
                        'name' => 'Synthetic day coverage',
                        'start_datetime' => $start,
                        'end_datetime' => $end,
                    ]);
                }
                self::assertSame($expected, $model->is_entire_date_blocked('2035-02-19'), $label);
            } finally {
                foreach ($ids as $id) {
                    $model->delete($id);
                }
            }
        }
    }
}
