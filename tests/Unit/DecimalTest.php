<?php

use App\Support\Decimal;

it('does exact decimal arithmetic without float drift', function () {
    expect(Decimal::add('0.1', '0.2', 2))->toBe('0.30')
        ->and(Decimal::mul('3', '33.3333', 2))->toBe('100.00')
        ->and(Decimal::mul('2.5', '0.005', 3))->toBe('0.013')   // half away from zero, like MySQL ROUND
        ->and(Decimal::sub('50000', '30000.01', 2))->toBe('19999.99')
        ->and(Decimal::cmp('10.000', '10', 3))->toBe(0)
        ->and(Decimal::isPositive('0.000'))->toBeFalse();
});
