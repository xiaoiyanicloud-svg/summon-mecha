<?php

namespace Webkul\Shop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryCompensator;

class RetrySkillDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $entitlementId)
    {
        $this->afterCommit();
    }

    public function handle(SkillDeliveryCompensator $compensator): void
    {
        // Recovery after a crash is scanner/lease driven. Laravel attempts are
        // intentionally independent from the durable three-attempt budget.
        $compensator->execute($this->entitlementId);
    }
}
