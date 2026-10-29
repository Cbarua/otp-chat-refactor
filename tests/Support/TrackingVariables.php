<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Compares the Pixel and GA variables a controller hands the templates, in any order.
 */
trait TrackingVariables
{
    private function assertTrackingVariables(array $expected, array $renderData): void
    {
        $keys = ['pixelId', 'externalId', 'phoneCapi', 'country', 'pageViewEventId', 'gaMeasurementId', 'leadEventId', 'regId', 'eventData'];
        $actual = array_intersect_key($renderData, array_flip($keys));
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }
}
