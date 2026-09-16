<?php

namespace Tests\Unit\V2\Support;

use App\V2\Support\TransientDatabaseException;
use Illuminate\Database\QueryException;
use PDOException;
use Tests\TestCase;

class TransientDatabaseExceptionTest extends TestCase
{
    public function test_matches_mysql_connection_refused(): void
    {
        $pdo = new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);
        $query = new QueryException(
            'mysql',
            'select 1',
            [],
            $pdo,
        );

        $this->assertTrue(TransientDatabaseException::matches($query));
        $this->assertTrue(TransientDatabaseException::matches($pdo));
    }

    public function test_does_not_match_business_errors(): void
    {
        $this->assertFalse(TransientDatabaseException::matches(new \RuntimeException('Discovery returned zero prospects')));
        $this->assertFalse(TransientDatabaseException::matches(null));
    }
}
