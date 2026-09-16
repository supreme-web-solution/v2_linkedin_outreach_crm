<?php

namespace App\V2\Support;

use Illuminate\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Detects MySQL / driver outages that must be retried — never treated as business step failures.
 */
final class TransientDatabaseException
{
    public static function matches(?Throwable $e): bool
    {
        if ($e === null) {
            return false;
        }

        $detector = app()->bound(LostConnectionDetectorContract::class)
            ? app(LostConnectionDetectorContract::class)
            : new LostConnectionDetector;

        if ($detector->causedByLostConnection($e)) {
            return true;
        }

        if ($e instanceof QueryException && in_array((string) $e->getCode(), ['2002', '2006', '2013'], true)) {
            return true;
        }

        $previous = $e->getPrevious();

        return $previous instanceof Throwable && self::matches($previous);
    }
}
