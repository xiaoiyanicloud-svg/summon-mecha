<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Webkul\Shop\Services\Scenes\SceneWorkflowException;

class SceneWorkflowSafety
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (SceneWorkflowException $e) {
            if (! $request->expectsJson() && $request->isMethod('post')) {
                return back()->withErrors(['scene' => $e->getMessage()]);
            }

            return $e->render();
        } catch (ValidationException $e) {
            if (! $request->expectsJson() && $request->isMethod('post')) {
                return back()->withErrors($e->errors());
            }

            return response()->json(['code' => 'VALIDATION_ERROR', 'message' => '请检查填写内容。', 'errors' => $e->errors()], 422);
        } catch (\Throwable $e) {
            $id = (string) \Illuminate\Support\Str::uuid();
            Log::error('SCENE_WORKFLOW_FAILED', ['error_id' => $id, 'exception' => get_class($e)]);

            if (! $request->expectsJson() && $request->isMethod('post')) {
                return back()->withErrors(['scene' => '操作未完成，请保留内容后重试。错误编号：'.$id]);
            }

            return response()->json(['code' => 'SCENE_OPERATION_FAILED', 'message' => '服务暂不可用，请保留提交内容后重试。', 'error_id' => $id], 500);
        }
    }
}
