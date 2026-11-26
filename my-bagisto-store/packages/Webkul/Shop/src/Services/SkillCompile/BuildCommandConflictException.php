<?php

namespace Webkul\Shop\Services\SkillCompile;

use DomainException;

/** 同一 seller 作用域内，同 idempotency_key 但请求载荷不同 → 409。 */
class BuildCommandConflictException extends DomainException
{
    public const ERROR_CODE = 'BUILD_COMMAND_CONFLICT';
}
