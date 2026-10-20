<?php

namespace Webkul\Shop\Services\Scenes;

class SceneWorkflowException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['code' => $this->errorCode, 'message' => $this->getMessage()], $this->status);
    }
}
