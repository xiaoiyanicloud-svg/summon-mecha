<?php

namespace Webkul\Admin\Exceptions;

/**
 * 后台发布工作台的对象级并发冲突。
 *
 * 与 Shop 层的 `EventRevisionConflictException` / `ContentRevisionConflictException`
 * 语义相同：调用方手里的页面数据已经过期。单独建一个 Admin 异常，是为了让
 * “hidden expected_revision_id 与当前 revision 不一致”也能落到同一条用户文案，
 * 而不是把 revision ID 当成 row_version 塞进 Shop 异常的参数里。
 */
class AdminPublishingConflictException extends \RuntimeException
{
    public function __construct(string $message = '已被他人修改，请刷新后再试。')
    {
        parent::__construct($message);
    }
}
