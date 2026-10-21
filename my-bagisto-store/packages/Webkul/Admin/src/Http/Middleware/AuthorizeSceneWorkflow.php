<?php

namespace Webkul\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthorizeSceneWorkflow
{
    public const ACTIONS = [
        'index' => 'view', 'show' => 'view', 'contact' => 'contact', 'requestAction' => 'requests',
        'assess' => 'assess', 'quote' => 'quote', 'quoteAction' => 'quote', 'publication' => 'publish',
        'supply' => 'supply', 'hold' => 'reserve', 'pause' => 'reserve', 'task' => 'deliver', 'replay' => 'operate',
        'commercial' => 'finance', 'reviewCommercial' => 'finance_review', 'returnResources' => 'deliver', 'resolveTicket' => 'support',
        'attachment' => 'view', 'upload' => 'requests',
        'recordOfflineAcceptance' => 'acceptance_record', 'reviewOfflineAcceptance' => 'acceptance_review',
        'configuration' => 'policy_view', 'policyDraft' => 'policy_write', 'policyDecision' => 'policy_review',
        'revisionEditor' => 'revision_write', 'contentEvidencePage' => 'evidence_view', 'contentEvidenceCreate' => 'revision_write', 'contentEvidenceDecision' => 'evidence_review',
        'survey' => 'assess', 'solution' => 'assess', 'solutionDecision' => 'solution_review', 'evidenceReview' => 'supply_review',
        'supplyRevoke' => 'supply_review', 'attachmentAction' => 'preserve', 'todoAssign' => 'operate', 'preservedAttachment' => 'preserve_read',
    ];

    public function handle(Request $request, Closure $next)
    {
        $method = $request->route()->getActionMethod();
        $permission = self::ACTIONS[$method] ?? null;
        if ($method === 'quoteAction' && in_array($request->route('action'), ['approve', 'return'], true)) {
            $permission = 'approve';
        }
        if ($method === 'publication') {
            $permission = match ($request->route('action')) {
                'draft', 'edit', 'review' => 'revision_write',
                'approve' => 'revision_review',
                'publish', 'withdraw' => 'publish',
                default => null,
            };
        }
        $key = $permission === 'view' ? 'catalog.scene-workflow' : 'catalog.scene-workflow.'.$permission;
        if (! $permission || ! bouncer()->hasPermission($key)) {
            abort(403);
        }

        return $next($request);
    }
}
