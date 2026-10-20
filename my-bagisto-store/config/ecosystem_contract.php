<?php

/*
|--------------------------------------------------------------------------
| 生态域契约清单（Q-01 单一事实源）
|--------------------------------------------------------------------------
|
| 本文件是生态赛事 / 资讯 / 报名 / 赛务公开 API 与后台动作的**契约基线**：
|
|   · `public_routes`：每条 Shop 公开 API 的路由名、URI、方法、鉴权、
|     幂等策略、限流与稳定错误码。新增/改名/改方法/改 handler 都必须同步本表，
|     否则 `php artisan ecosystem:contract-check` 直接失败（阻断构建）。
|   · `error_codes`：生态域允许出现在线上的稳定错误码全集；
|     代码里出现未登记的错误码同样失败。
|   · `state_inventories`：模型状态常量的冻结快照。新增状态常量而未更新本表
|     会被判为漂移 —— 状态不是随手加的字符串，它是状态机的一部分。
|   · `vocabularies`：发布状态 / 处置轴 / 媒体审核 / revision 审核词表。
|   · `documented_resources`：公开 DTO 必须出现在 OpenAPI 的
|     `components.schemas[*]['x-resource-class']` 中，字段变化必须同步文档。
|
| 这不是运行时行为开关：它不改变接口，只让「文档没写就跑不起来」。
| 后台路由 → 权限的登记仍在 config/ecosystem_admin.php（本门禁做双向核对）。
|
| 复核命令：
|   php artisan ecosystem:contract-check
|   php artisan ecosystem:contract-check --json=build/ecosystem-contract.json
|
*/

return [
    'version' => '2026-10-20-q1',

    'admin_route_prefix' => 'admin.catalog.ecosystem.',

    'public_route_prefixes' => [
        'shop.api.events.',
        'shop.api.content.',
        'shop.api.customer.competition.',
        'shop.api.customer.event-registrations.',
        'shop.api.customer.registration-requests.',
        'shop.api.customer.favorites.',
    ],

    // 相对 base_path；CI/部署可用 ECOSYSTEM_OPENAPI_PATH 覆盖。契约门禁是构建时检查，
    // 生产运行时不读取本文件。
    'openapi_path' => env('ECOSYSTEM_OPENAPI_PATH', '/../docs/ecosystem/openapi.json'),

    'public_routes' => [
        'shop.api.content.posts.index' => [
            'uri' => '/api/content/posts',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'ContentController@index',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'CONTENT_NOT_FOUND',
                'CONTENT_UNAVAILABLE',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.content.posts.media' => [
            'uri' => '/api/content/posts/{slug}/media/{mediaId}',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'EcosystemMediaController@content',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'MEDIA_NOT_FOUND',
            ],
        ],
        'shop.api.content.posts.show' => [
            'uri' => '/api/content/posts/{slug}',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'ContentController@show',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'CONTENT_NOT_FOUND',
                'CONTENT_UNAVAILABLE',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.appeals.attachments.show' => [
            'uri' => '/api/customer/competition/appeal-attachments/{attachmentId}',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionAppealController@downloadEvidence',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_RESULT_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.appeals.attachments.store' => [
            'uri' => '/api/customer/competition/appeals/{id}/attachments',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionAppealController@uploadEvidence',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_RESULT_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.appeals.index' => [
            'uri' => '/api/customer/competition/appeals',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionAppealController@index',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_RESULT_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.appeals.show' => [
            'uri' => '/api/customer/competition/appeals/{id}',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionAppealController@show',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_RESULT_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.appeals.store' => [
            'uri' => '/api/customer/competition/appeals',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionAppealController@store',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_RESULT_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.appeals.withdraw' => [
            'uri' => '/api/customer/competition/appeals/{id}/withdraw',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionAppealController@withdraw',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_RESULT_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.attachments.show' => [
            'uri' => '/api/customer/competition/attachments/{attachmentId}',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionSubmissionController@downloadAttachment',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_ENTRY_NOT_FOUND',
                'COMPETITION_SUBMISSION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.showcases.consent' => [
            'uri' => '/api/customer/competition/showcases/{id}/consent',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionShowcaseController@consent',
            'idempotency' => 'version_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_SHOWCASE_STATE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.showcases.index' => [
            'uri' => '/api/customer/competition/showcases',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionShowcaseController@index',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_SHOWCASE_STATE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.showcases.skill_review' => [
            'uri' => '/api/customer/competition/showcases/{id}/skill-review',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionShowcaseController@requestSkillReview',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_SHOWCASE_STATE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.submission.attachments.store' => [
            'uri' => '/api/customer/competition/teams/{teamId}/submission/attachments',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionSubmissionController@uploadAttachment',
            'idempotency' => 'version_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_ENTRY_NOT_FOUND',
                'COMPETITION_SUBMISSION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.submission.draft' => [
            'uri' => '/api/customer/competition/teams/{teamId}/submission/draft',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionSubmissionController@saveDraft',
            'idempotency' => 'version_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_ENTRY_NOT_FOUND',
                'COMPETITION_SUBMISSION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.submission.submit' => [
            'uri' => '/api/customer/competition/teams/{teamId}/submission/submit',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionSubmissionController@submit',
            'idempotency' => 'version_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_ENTRY_NOT_FOUND',
                'COMPETITION_SUBMISSION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.submission.workspace' => [
            'uri' => '/api/customer/competition/teams/{teamId}/submission',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionSubmissionController@workspace',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_ENTRY_NOT_FOUND',
                'COMPETITION_SUBMISSION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.decline' => [
            'uri' => '/api/customer/competition/teams/decline',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@decline',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.index' => [
            'uri' => '/api/customer/competition/teams',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@index',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.invites.store' => [
            'uri' => '/api/customer/competition/teams/{id}/invites',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@invite',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.join' => [
            'uri' => '/api/customer/competition/teams/join',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@join',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.leave' => [
            'uri' => '/api/customer/competition/teams/{id}/leave',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@leave',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.register' => [
            'uri' => '/api/customer/competition/teams/{id}/register',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@register',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.show' => [
            'uri' => '/api/customer/competition/teams/{id}',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@show',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.store' => [
            'uri' => '/api/customer/competition/teams',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@store',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.competition.teams.transfer' => [
            'uri' => '/api/customer/competition/teams/{id}/transfer',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'CompetitionTeamController@transfer',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
                'COMPETITION_TEAM_NOT_FOUND',
                'COMPETITION_TRACK_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.cancel' => [
            'uri' => '/api/customer/event-registrations/{publicNo}/cancel',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@cancel',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.changes' => [
            'uri' => '/api/customer/event-registrations/{publicNo}/changes',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@changes',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.changes.ack' => [
            'uri' => '/api/customer/event-registrations/{publicNo}/changes/{changeId}/ack',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@ackChange',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.credential' => [
            'uri' => '/api/customer/event-registrations/{publicNo}/credential',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@credential',
            'idempotency' => 'state_guard',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.index' => [
            'uri' => '/api/customer/event-registrations',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@index',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.reapply' => [
            'uri' => '/api/customer/event-registrations/{publicNo}/reapply',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@reapply',
            'idempotency' => 'request_key',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.event-registrations.show' => [
            'uri' => '/api/customer/event-registrations/{publicNo}',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@show',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.favorites.destroy' => [
            'uri' => '/api/customer/favorites/{type}/{key}',
            'methods' => [
                'DELETE',
            ],
            'auth' => 'customer',
            'handler' => 'FavoriteController@destroy',
            'idempotency' => 'unique_key',
            'throttled' => true,
            'errors' => [
                'ECOSYSTEM_FAVORITES_DISABLED',
                'FAVORITE_LIMIT_REACHED',
                'FAVORITE_TARGET_NOT_FOUND',
                'FAVORITE_TYPE_INVALID',
                'SERVER_ERROR',
                'SERVICE_UNAVAILABLE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.favorites.index' => [
            'uri' => '/api/customer/favorites',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'FavoriteController@index',
            'idempotency' => 'unique_key',
            'throttled' => false,
            'errors' => [
                'ECOSYSTEM_FAVORITES_DISABLED',
                'FAVORITE_LIMIT_REACHED',
                'FAVORITE_TARGET_NOT_FOUND',
                'FAVORITE_TYPE_INVALID',
                'SERVER_ERROR',
                'SERVICE_UNAVAILABLE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.favorites.store' => [
            'uri' => '/api/customer/favorites',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'FavoriteController@store',
            'idempotency' => 'unique_key',
            'throttled' => true,
            'errors' => [
                'ECOSYSTEM_FAVORITES_DISABLED',
                'FAVORITE_LIMIT_REACHED',
                'FAVORITE_TARGET_NOT_FOUND',
                'FAVORITE_TYPE_INVALID',
                'SERVER_ERROR',
                'SERVICE_UNAVAILABLE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.customer.registration-requests.show' => [
            'uri' => '/api/customer/registration-requests/{requestKey}',
            'methods' => [
                'GET',
            ],
            'auth' => 'customer',
            'handler' => 'EventRegistrationController@requestStatus',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'REGISTRATION_NOT_FOUND',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.events.competition.results' => [
            'uri' => '/api/events/{id}/competition/results',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'CompetitionPublicController@results',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
            ],
        ],
        'shop.api.events.competition.schedule' => [
            'uri' => '/api/events/{id}/competition/schedule',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'CompetitionPublicController@schedule',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
            ],
        ],
        'shop.api.events.competition.show' => [
            'uri' => '/api/events/{id}/competition',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'CompetitionPublicController@show',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
            ],
        ],
        'shop.api.events.competition.showcases' => [
            'uri' => '/api/events/{id}/competition/showcases',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'CompetitionPublicController@showcases',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'COMPETITION_NOT_FOUND',
            ],
        ],
        'shop.api.events.index' => [
            'uri' => '/api/events',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'EventController@index',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'EVENT_REGISTRATION_DISABLED',
                'EVENT_UNAVAILABLE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.events.media' => [
            'uri' => '/api/events/{id}/media/{mediaId}',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'EcosystemMediaController@event',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'MEDIA_NOT_FOUND',
            ],
        ],
        'shop.api.events.register' => [
            'uri' => '/api/events/{id}/register',
            'methods' => [
                'POST',
            ],
            'auth' => 'customer',
            'handler' => 'EventController@register',
            'idempotency' => 'request_key',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'EVENT_REGISTRATION_DISABLED',
                'EVENT_UNAVAILABLE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
        'shop.api.events.show' => [
            'uri' => '/api/events/{id}',
            'methods' => [
                'GET',
            ],
            'auth' => 'optional',
            'handler' => 'EventController@show',
            'idempotency' => 'read',
            'throttled' => false,
            'errors' => [
                'EVENT_NOT_FOUND',
                'EVENT_REGISTRATION_DISABLED',
                'EVENT_UNAVAILABLE',
                'UNAUTHENTICATED',
                'VALIDATION_ERROR',
            ],
        ],
    ],

    'error_codes' => [
        'CHANGE_ACK_CLOSED',
        'CHECKIN_NOT_ALLOWED',
        'CHECKIN_TOKEN_INVALID',
        'COMPETITION_APPEAL_CLOSED',
        'COMPETITION_APPEAL_EVIDENCE_INVALID',
        'COMPETITION_APPEAL_FORBIDDEN',
        'COMPETITION_APPEAL_NOT_FOUND',
        'COMPETITION_APPEAL_RESULT_CHANGED',
        'COMPETITION_ATTACHMENT_INVALID',
        'COMPETITION_ATTACHMENT_NOT_FOUND',
        'COMPETITION_AWARD_NOT_FOUND',
        'COMPETITION_AWARD_STATE',
        'COMPETITION_CONSENT_REQUIRED',
        'COMPETITION_DISABLED',
        'COMPETITION_DUTY_SEPARATION',
        'COMPETITION_ELIGIBILITY_FAILED',
        'COMPETITION_ENTRY_FROZEN',
        'COMPETITION_ENTRY_NOT_ACTIVE',
        'COMPETITION_ENTRY_NOT_FOUND',
        'COMPETITION_INVITE_EXPIRED',
        'COMPETITION_INVITE_INVALID',
        'COMPETITION_MULTI_TEAM_NOT_ALLOWED',
        'COMPETITION_NOT_FOUND',
        'COMPETITION_RANKING_INVALID',
        'COMPETITION_RANKING_REQUIRED',
        'COMPETITION_RESULT_NOT_FOUND',
        'COMPETITION_RESULT_STATE',
        'COMPETITION_RULES_UNCONFIRMED',
        'COMPETITION_SCHEDULE_INVALID',
        'COMPETITION_SCHEDULE_PUBLISHED',
        'COMPETITION_SHOWCASE_NOT_FOUND',
        'COMPETITION_SHOWCASE_STATE',
        'COMPETITION_SUBMISSION_CLOSED',
        'COMPETITION_SUBMISSION_IMMUTABLE',
        'COMPETITION_SUBMISSION_NOT_FOUND',
        'COMPETITION_SUBMISSION_VERSION_STALE',
        'COMPETITION_TEAM_FORBIDDEN',
        'COMPETITION_TEAM_FULL',
        'COMPETITION_TEAM_MEMBER_STATE',
        'COMPETITION_TEAM_NAME_TAKEN',
        'COMPETITION_TEAM_NOT_FOUND',
        'COMPETITION_TRACK_NOT_ACTIVE',
        'COMPETITION_TRACK_NOT_FOUND',
        'COMPETITION_UNIT_MISMATCH',
        'COMPETITION_VALIDATION_ERROR',
        'COMPETITION_VERSION_STALE',
        'CONTENT_NOT_FOUND',
        'CONTENT_REVISION_CONFLICT',
        'CONTENT_SLUG_TAKEN',
        'CONTENT_SOURCE_REQUIRED',
        'CONTENT_TRANSITION_INVALID',
        'CONTENT_UNAVAILABLE',
        'ECOSYSTEM_FAVORITES_DISABLED',
        'EVENT_NOT_FOUND',
        'EVENT_ORGANIZER_UNVERIFIED',
        'EVENT_REGISTRATION_DISABLED',
        'EVENT_REVISION_CONFLICT',
        'EVENT_SOURCE_REQUIRED',
        'EVENT_TIME_INVALID',
        'EVENT_TRANSITION_INVALID',
        'EVENT_UNAVAILABLE',
        'EXPORT_EXPIRED',
        'EXPORT_FORBIDDEN',
        'FAVORITE_LIMIT_REACHED',
        'FAVORITE_TARGET_NOT_FOUND',
        'FAVORITE_TYPE_INVALID',
        'MEDIA_INVALID',
        'MEDIA_NOT_FOUND',
        'REGISTRATION_ALREADY_ACTIVE',
        'REGISTRATION_ATTEMPT_LIMIT',
        'REGISTRATION_CLOSED',
        'REGISTRATION_DISABLED',
        'REGISTRATION_FORBIDDEN',
        'REGISTRATION_FULL',
        'REGISTRATION_NOT_FOUND',
        'REGISTRATION_NOT_OPEN',
        'REGISTRATION_REQUEST_CONFLICT',
        'REGISTRATION_REVIEW_CLOSED',
        'REGISTRATION_TRANSITION_INVALID',
        'REGISTRATION_VALIDATION_ERROR',
        'REGISTRATION_VERSION_STALE',
        'SERVER_ERROR',
        'SERVICE_UNAVAILABLE',
        'UNAUTHENTICATED',
        'VALIDATION_ERROR',
    ],

    'state_inventories' => [
        'registration_attempt' => [
            'class' => 'Webkul\\Shop\\Models\\EventRegistrationAttempt',
            'values' => [
                'canceled',
                'checked_in',
                'confirmed',
                'rejected',
                'submitted',
            ],
        ],
        'competition_team' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionTeam',
            'values' => [
                'active',
                'disbanded',
            ],
        ],
        'competition_entry' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionEntry',
            'values' => [
                'confirmed',
                'disqualified',
                'draft',
                'submitted',
                'withdrawn',
            ],
        ],
        'competition_submission' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionSubmission',
            'values' => [
                'correction_required',
                'draft',
                'locked',
                'submitted',
                'withdrawn',
            ],
        ],
        'competition_match' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionMatch',
            'values' => [
                'canceled',
                'draft',
                'published',
            ],
        ],
        'competition_result' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionResult',
            'values' => [
                'draft',
                'final',
                'provisional',
                'revising',
                'submitted',
            ],
        ],
        'competition_result_version' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionResultVersion',
            'values' => [
                'draft',
                'final',
                'provisional',
                'submitted',
                'superseded',
            ],
        ],
        'competition_appeal' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionAppeal',
            'values' => [
                'accepted',
                'decided',
                'investigating',
                'rejected',
                'submitted',
                'withdrawn',
            ],
        ],
        'competition_award' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionAward',
            'values' => [
                'draft',
                'pending_review',
                'published',
                'revoked',
            ],
        ],
        'competition_award_version' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionAwardVersion',
            'values' => [
                'draft',
                'pending_review',
                'published',
                'revoked',
                'superseded',
            ],
        ],
        'competition_showcase' => [
            'class' => 'Webkul\\Shop\\Models\\CompetitionShowcase',
            'values' => [
                'approved',
                'draft',
                'published',
                'rejected',
                'submitted',
                'withdrawn',
            ],
        ],
    ],

    'vocabularies' => [
        'publish_status' => [
            'draft',
            'in_review',
            'approved',
            'scheduled',
            'published',
        ],
        'disposition' => [
            'normal',
            'withheld',
            'retracted',
        ],
        'media_status' => [
            'pending',
            'approved',
            'rejected',
            'quarantined',
        ],
        'revision_review_state' => [
            'draft',
            'in_review',
            'approved',
            'rejected',
        ],
    ],

    'documented_resources' => [
        'Webkul\\Shop\\Http\\Resources\\Ecosystem\\EventPublicResource' => 'EventPublic',
        'Webkul\\Shop\\Http\\Resources\\Ecosystem\\ContentPublicResource' => 'ContentPublic',
        'Webkul\\Shop\\Http\\Resources\\Ecosystem\\MediaPublicResource' => 'MediaPublic',
        'Webkul\\Shop\\Http\\Resources\\Ecosystem\\RegistrationResource' => 'RegistrationPublic',
        'Webkul\\Shop\\Http\\Resources\\Ecosystem\\CompetitionResource' => 'CompetitionPublic',
    ],

];
