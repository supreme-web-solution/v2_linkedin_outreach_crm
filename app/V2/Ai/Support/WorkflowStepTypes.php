<?php

namespace App\V2\Ai\Support;

final class WorkflowStepTypes
{
    public const EVALUATE_EXISTING = 'evaluate_existing';

    public const DISCOVER = 'discover';

    public const PREPARE_OUTREACH = 'prepare_outreach';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const EXECUTE_OUTREACH = 'execute_outreach';

    public const VERIFY = 'verify';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::EVALUATE_EXISTING,
            self::DISCOVER,
            self::PREPARE_OUTREACH,
            self::AWAITING_APPROVAL,
            self::EXECUTE_OUTREACH,
            self::VERIFY,
        ];
    }
}
