<?php

namespace Webkul\Shop\Http\Controllers\API\Dev;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\API\APIController;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperOnboardingService;

/**
 * `/api/dev/agreements` —— 协议签署（v3.4 步骤 9）。
 */
class AgreementController extends APIController
{
    public function __construct(protected DeveloperOnboardingService $onboarding) {}

    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $signed = $profile->agreements()
            ->get()
            ->keyBy('doc_type');

        $data = [];

        foreach ((array) config('developer_portal.agreements', []) as $docType => $doc) {
            $data[] = [
                'doc_type'    => $docType,
                'title'       => $doc['title'] ?? $docType,
                'version'     => $doc['version'] ?? '1.0',
                // ⚠ 必须回正文：签署留痕哈希的就是这段正文字节（见 DeveloperOnboardingService），
                //   不回正文的话纯前端根本渲染不出签署页。
                'text'        => $doc['text'] ?? '',
                'text_hash'   => hash('sha256', (string) ($doc['text'] ?? '')),
                'signed'      => isset($signed[$docType]),
                'signed_at'   => isset($signed[$docType])
                    ? optional($signed[$docType]->signed_at)->toDateTimeString()
                    : null,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $docs = (array) config('developer_portal.agreements', []);

        $rules = [];
        foreach (array_keys($docs) as $docType) {
            $rules['accept_'.$docType] = ['required', 'accepted'];
        }

        $payload = $request->validate($rules);

        $accepted = [];
        foreach (array_keys($docs) as $docType) {
            $accepted[$docType] = (bool) ($payload['accept_'.$docType] ?? false);
        }

        $result = $this->onboarding->signAgreements($this->profile($request), $accepted, $request);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message'], 'code' => 'AGREEMENT_INCOMPLETE'], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'data'    => ['signed' => $result['signed'] ?? []],
        ]);
    }

    protected function profile(Request $request): DeveloperProfile
    {
        return $request->attributes->get('developer_profile');
    }
}
