<?php

namespace Webkul\Shop\Services\Scenes;

/** 由服务端适配器持有的请求作用域能力；客户端 additional 无法制造此上下文。 */
class SceneOrderWriteContext
{
    private array $stack = [];

    public function run(array $data, callable $write): object
    {
        $this->stack[] = SceneWorkflow::hash($data);
        try {
            return $write();
        } finally {
            array_pop($this->stack);
        }
    }

    public function allows(array $data): bool
    {
        $hash = end($this->stack);

        return $hash !== false && hash_equals($hash, SceneWorkflow::hash($data));
    }
}
