<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Routing\Router;
use ReflectionClass;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 生态域契约门禁（Q-01）。
 *
 * 只在构建 / CI / 发布前运行，不参与请求链路。它回答四个问题：
 *
 *   1. **路由有没有漏登记的授权**？
 *      —— 后台 `admin.catalog.ecosystem.*` 必须与 `config/ecosystem_admin.php`
 *      双向一致，权限 key 必须存在于 `Admin/Config/acl.php`。
 *      新增后台写路由而不登记权限时，本检查失败（阻断构建）。
 *   2. **公开 API 有没有脱离契约**？
 *      —— 每条 `shop.api.*` 生态路由必须出现在 `config/ecosystem_contract.php`，
 *      URI / 方法 / handler 必须一致；写路由必须有明确鉴权与幂等策略。
 *   3. **错误码 / 状态词表有没有漂移**？
 *      —— 代码使用的 `ApiErrorCode` 常量和域异常码必须已登记；
 *      模型状态常量必须与冻结快照完全相同；发布转移表只能使用已声明状态。
 *   4. **OpenAPI 有没有跟上**？
 *      —— 每条契约路由必须在 `docs/ecosystem/openapi.json` 有同方法 operation，
 *      错误码一致；公开 DTO 必须有 schema 且 `x-resource-class` 指向真实类。
 *
 * 失败是 fail-closed：命令返回非零退出码，CI 直接红。
 */
class ContractCoverageChecker
{
    public function __construct(
        private readonly Router $router,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     version: string,
     *     stats: array<string, int>,
     *     violations: array<int, array{code: string, subject: string, detail: string}>
     * }
     */
    public function check(): array
    {
        $violations = [];
        $add = static function (string $code, string $subject, string $detail) use (&$violations): void {
            $violations[] = ['code' => $code, 'subject' => $subject, 'detail' => $detail];
        };

        $contract = (array) config('ecosystem_contract', []);
        $contractRoutes = (array) ($contract['public_routes'] ?? []);
        $version = (string) ($contract['version'] ?? '');

        $routes = $this->routes();
        $adminPrefix = (string) ($contract['admin_route_prefix'] ?? 'admin.catalog.ecosystem.');
        $publicPrefixes = (array) ($contract['public_route_prefixes'] ?? []);

        $adminRoutes = [];
        $publicRoutes = [];

        foreach ($routes as $name => $route) {
            if (str_starts_with($name, $adminPrefix)) {
                $adminRoutes[$name] = $route;
            }

            foreach ($publicPrefixes as $prefix) {
                if (str_starts_with($name, (string) $prefix)) {
                    $publicRoutes[$name] = $route;

                    break;
                }
            }
        }

        $this->checkAdminMapping($adminRoutes, $routes, $add);
        $this->checkPublicCoverage($publicRoutes, $contractRoutes, $add);
        $this->checkErrorCodes($contractRoutes, $contract, $add);
        $this->checkStateVocabulary($contract, $add);
        $this->checkOpenApi($contract, $contractRoutes, $add);

        return [
            'ok'         => $violations === [],
            'version'    => $version,
            'stats'      => [
                'admin_routes'     => count($adminRoutes),
                'public_routes'    => count($publicRoutes),
                'contracted_routes'=> count($contractRoutes),
                'error_codes'      => count((array) ($contract['error_codes'] ?? [])),
                'state_inventories'=> count((array) ($contract['state_inventories'] ?? [])),
            ],
            'violations' => $violations,
        ];
    }

    /**
     * @return array<string, array{uri: string, methods: array<int, string>, action: string, handler: string}>
     */
    private function routes(): array
    {
        $result = [];

        foreach ($this->router->getRoutes() as $route) {
            $name = $route->getName();

            if (! is_string($name) || $name === '') {
                continue;
            }

            $action = (string) $route->getActionName();
            $handler = $action;

            if (str_contains($action, '@')) {
                [$class, $method] = explode('@', $action, 2);
                $handler = class_basename($class).'@'.$method;
            }

            $result[$name] = [
                'uri'     => '/'.ltrim((string) $route->uri(), '/'),
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'action'  => $action,
                'handler' => $handler,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{uri: string, methods: array<int, string>, action: string, handler: string}>  $adminRoutes
     * @param  array<string, array{uri: string, methods: array<int, string>, action: string, handler: string}>  $allRoutes
     * @param  callable(string, string, string): void  $add
     */
    private function checkAdminMapping(array $adminRoutes, array $allRoutes, callable $add): void
    {
        $mapping = (array) config('ecosystem_admin.route_permissions', []);
        $aclKeys = $this->aclKeys();

        foreach ($adminRoutes as $name => $route) {
            if (! isset($mapping[$name])) {
                $add('admin_route_unmapped', $name, '后台路由未在 config/ecosystem_admin.php 登记权限');
            }
        }

        foreach ($mapping as $name => $permission) {
            if (! isset($adminRoutes[$name])) {
                $add('admin_mapping_stale', (string) $name, '登记表里的路由不存在（改名或删除后未同步）');
            }

            if (! isset($aclKeys[(string) $permission])) {
                $add('admin_permission_missing_acl', (string) $name, '权限 key 不在 Admin/Config/acl.php：'.$permission);
            }
        }

        foreach ($this->aclEntries() as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $route = (string) ($entry['route'] ?? '');

            if (! str_starts_with($key, 'ecosystem.')) {
                continue;
            }

            if ($route !== '' && ! isset($allRoutes[$route])) {
                $add('acl_route_orphan', $key, 'ACL 指向不存在的路由：'.$route);
            }
        }
    }

    /**
     * @param  array<string, array{uri: string, methods: array<int, string>, action: string, handler: string}>  $publicRoutes
     * @param  array<string, array<string, mixed>>  $contractRoutes
     * @param  callable(string, string, string): void  $add
     */
    private function checkPublicCoverage(array $publicRoutes, array $contractRoutes, callable $add): void
    {
        foreach ($publicRoutes as $name => $route) {
            if (! isset($contractRoutes[$name])) {
                $add('public_route_uncontracted', $name, '公开路由未在 config/ecosystem_contract.php 登记');

                continue;
            }

            $spec = (array) $contractRoutes[$name];
            $expectedMethods = $this->methods($spec);
            $actualMethods = $route['methods'];

            sort($expectedMethods);
            sort($actualMethods);

            if ($expectedMethods !== $actualMethods) {
                $add('contract_method_mismatch', $name, '契约方法 '.implode(',', $expectedMethods).' ≠ 实际 '.implode(',', $actualMethods));
            }

            if ((string) ($spec['uri'] ?? '') !== $route['uri']) {
                $add('contract_uri_mismatch', $name, '契约 URI '.($spec['uri'] ?? '').' ≠ 实际 '.$route['uri']);
            }

            if ((string) ($spec['handler'] ?? '') !== $route['handler']) {
                $add('contract_handler_mismatch', $name, '契约 handler '.($spec['handler'] ?? '').' ≠ 实际 '.$route['handler']);
            }
        }

        $declaredErrors = array_flip((array) (config('ecosystem_contract.error_codes') ?: []));

        foreach ($contractRoutes as $name => $spec) {
            $spec = (array) $spec;

            if (! isset($publicRoutes[$name])) {
                $add('contract_route_missing', (string) $name, '契约登记了路由，但应用里没有注册');

                continue;
            }

            $isWrite = (bool) array_diff($this->methods($spec), ['GET']);
            $auth = (string) ($spec['auth'] ?? '');
            $idempotency = (string) ($spec['idempotency'] ?? '');

            if ($isWrite && ! in_array($auth, ['customer', 'admin'], true) && empty($spec['public_write_reason'])) {
                $add('write_route_auth_missing', (string) $name, '写路由必须有明确鉴权（或显式 public_write_reason）');
            }

            if ($isWrite && ($idempotency === '' || $idempotency === 'read')) {
                $add('write_route_idempotency_missing', (string) $name, '写路由必须声明幂等策略');
            }

            foreach ((array) ($spec['errors'] ?? []) as $code) {
                if (! isset($declaredErrors[(string) $code])) {
                    $add('route_error_undeclared', (string) $name, '路由错误码未在 error_codes 登记：'.$code);
                }
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $contractRoutes
     * @param  array<string, mixed>  $contract
     * @param  callable(string, string, string): void  $add
     */
    private function checkErrorCodes(array $contractRoutes, array $contract, callable $add): void
    {
        $constants = array_values((new ReflectionClass(ApiErrorCode::class))->getConstants());
        $declared = array_values((array) ($contract['error_codes'] ?? []));
        $declaredFlip = array_flip($declared);

        foreach ($declared as $code) {
            if (! in_array($code, $constants, true)) {
                $add('error_code_unknown', (string) $code, '契约登记了 ApiErrorCode 中不存在的错误码');
            }
        }

        foreach ($this->errorCodeSources() as $file => $source) {
            preg_match_all('/ApiErrorCode::([A-Z0-9_]+)/', $source, $matches);

            foreach ($matches[1] as $code) {
                if (! isset($declaredFlip[$code])) {
                    $add('error_code_undeclared', (string) $code, '代码使用了未登记的错误码（文件：'.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).'）');
                }
            }

            preg_match_all("/new (?:self|RegistrationException|CompetitionException)\(\\s*'([A-Z0-9_]+)'/", $source, $matches);

            foreach ($matches[1] as $code) {
                if (! isset($declaredFlip[$code])) {
                    $add('error_code_undeclared', (string) $code, '域异常使用了未登记的错误码（文件：'.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).'）');
                }
            }
        }

        // 路由级错误码与错误登记必须互相可解释：路由声明的码必须被某个控制器/服务实际使用。
        $used = [];
        foreach ($this->errorCodeSources() as $source) {
            preg_match_all('/ApiErrorCode::([A-Z0-9_]+)/', $source, $matches);
            $used += array_flip($matches[1]);
            preg_match_all("/new (?:self|RegistrationException|CompetitionException)\(\\s*'([A-Z0-9_]+)'/", $source, $matches);
            $used += array_flip($matches[1]);
        }

        foreach ($contractRoutes as $name => $spec) {
            foreach ((array) ($spec['errors'] ?? []) as $code) {
                if (! isset($used[(string) $code])) {
                    $add('route_error_unused', (string) $name, '路由声明了代码中从未使用的错误码：'.$code);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  callable(string, string, string): void  $add
     */
    private function checkStateVocabulary(array $contract, callable $add): void
    {
        $publishing = (array) config('ecosystem_publishing', []);
        $statuses = array_values((array) ($publishing['publish_statuses'] ?? []));
        $actions = [];

        foreach ((array) ($publishing['actions'] ?? []) as $list) {
            foreach ((array) $list as $action) {
                $actions[] = (string) $action;
            }
        }

        foreach ((array) ($publishing['transitions'] ?? []) as $domain => $byAction) {
            if (! in_array($domain, ['event', 'content'], true)) {
                $add('state_domain_unknown', (string) $domain, '发布转移表出现未知域');
            }

            foreach ((array) $byAction as $action => $transitions) {
                if (! in_array((string) $action, $actions, true)) {
                    $add('state_action_unknown', (string) $domain, '转移动作未在 actions 词表：'.$action);
                }

                foreach ((array) $transitions as $from => $to) {
                    if (! in_array((string) $from, $statuses, true)) {
                        $add('state_source_unknown', (string) $domain, '转移源状态未声明：'.$from);
                    }

                    if (! in_array((string) $to, $statuses, true)) {
                        $add('state_target_unknown', (string) $domain, '转移目标状态未声明：'.$to);
                    }
                }
            }
        }

        $outboxTypes = array_flip((array) config('ecosystem_outbox.event_types', []));

        foreach ((array) ($publishing['outbox_event_types'] ?? []) as $domain => $byAction) {
            foreach ((array) $byAction as $action => $type) {
                if (! isset($outboxTypes[(string) $type])) {
                    $add('outbox_type_unknown', (string) $domain.'.'.$action, 'outbox 映射未在 ecosystem_outbox.event_types 注册：'.$type);
                }
            }
        }

        foreach ((array) ($contract['state_inventories'] ?? []) as $key => $inventory) {
            $class = (string) ($inventory['class'] ?? '');
            $expected = array_values((array) ($inventory['values'] ?? []));

            if (! class_exists($class)) {
                $add('state_class_missing', (string) $key, '状态快照引用的类不存在：'.$class);

                continue;
            }

            $actual = [];
            foreach ((new ReflectionClass($class))->getConstants() as $constant => $value) {
                if (str_starts_with((string) $constant, 'STATUS_') && is_string($value)) {
                    $actual[] = $value;
                }
            }

            $actual = array_values(array_unique($actual));
            sort($actual);
            $sortedExpected = $expected;
            sort($sortedExpected);

            if ($actual !== $sortedExpected) {
                $add(
                    'state_inventory_drift',
                    (string) $key,
                    '状态常量与冻结快照不一致；新增 '.implode(',', array_diff($actual, $sortedExpected))
                    .' / 缺失 '.implode(',', array_diff($sortedExpected, $actual)),
                );
            }
        }

        foreach ((array) ($contract['vocabularies'] ?? []) as $name => $values) {
            $values = (array) $values;

            if ($values === [] || count($values) !== count(array_unique($values))) {
                $add('vocabulary_invalid', (string) $name, '词表为空或含重复值');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, array<string, mixed>>  $contractRoutes
     * @param  callable(string, string, string): void  $add
     */
    private function checkOpenApi(array $contract, array $contractRoutes, callable $add): void
    {
        $path = base_path().(string) ($contract['openapi_path'] ?? '');

        if ((string) ($contract['openapi_path'] ?? '') === '' || ! is_file($path)) {
            $add('openapi_missing', (string) ($contract['openapi_path'] ?? ''), 'OpenAPI 文件不存在');

            return;
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document)) {
            $add('openapi_invalid', $path, 'OpenAPI JSON 解析失败');

            return;
        }

        if ((string) ($document['info']['x-contract-version'] ?? '') !== (string) ($contract['version'] ?? '')) {
            $add('openapi_version_mismatch', $path, 'OpenAPI 契约版本与 config/ecosystem_contract.php 不一致');
        }

        $operations = [];

        foreach ((array) ($document['paths'] ?? []) as $uri => $pathItem) {
            foreach ((array) $pathItem as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $method = strtoupper((string) $method);

                if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    continue;
                }

                $operationId = (string) ($operation['operationId'] ?? '');

                if ($operationId === '') {
                    $add('openapi_operation_unnamed', (string) $uri, $method.' 缺少 operationId');

                    continue;
                }

                $operations[$operationId][] = [
                    'uri'          => (string) $uri,
                    'method'       => $method,
                    'error_codes'  => (array) ($operation['x-error-codes'] ?? []),
                ];
            }
        }

        foreach ($contractRoutes as $name => $spec) {
            $spec = (array) $spec;

            if (! isset($operations[$name])) {
                $add('openapi_operation_missing', (string) $name, '契约路由没有 OpenAPI operation');

                continue;
            }

            $found = $operations[$name];

            if (count($found) > 1) {
                $add('openapi_operation_duplicate', (string) $name, '同一 operationId 出现多次');
            }

            $expected = $this->methods($spec);
            $actual = array_values(array_unique(array_column($found, 'method')));
            sort($expected);
            sort($actual);

            if ($expected !== $actual) {
                $add('openapi_method_mismatch', (string) $name, 'OpenAPI 方法 '.implode(',', $actual).' ≠ 契约 '.implode(',', $expected));
            }

            $declared = (array) ($spec['errors'] ?? []);
            sort($declared);
            $documented = array_values(array_unique(array_merge(...array_column($found, 'error_codes') ?: [[]])));
            sort($documented);

            if ($declared !== $documented) {
                $add('openapi_error_codes_mismatch', (string) $name, 'OpenAPI x-error-codes 与契约登记不一致');
            }
        }

        foreach ($operations as $name => $found) {
            if (! isset($contractRoutes[$name])) {
                $add('openapi_operation_orphan', (string) $name, 'OpenAPI 记录了契约中不存在的路由');
            }
        }

        $this->checkRefs($document, $add);
        $this->checkDocumentedResources($contract, $document, $add);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  callable(string, string, string): void  $add
     */
    private function checkRefs(array $document, callable $add): void
    {
        $refs = [];
        $walk = static function (mixed $node) use (&$walk, &$refs): void {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $refs[$value] = true;
                } else {
                    $walk($value);
                }
            }
        };
        $walk($document);

        foreach (array_keys($refs) as $ref) {
            if (! str_starts_with($ref, '#/')) {
                $add('openapi_ref_external', $ref, '契约只允许本地 $ref（外部引用无法离线校验）');

                continue;
            }

            $node = $document;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

                if (! is_array($node) || ! array_key_exists($segment, $node)) {
                    $add('openapi_ref_missing', $ref, '本地 $ref 无法解析');

                    continue 2;
                }

                $node = $node[$segment];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $document
     * @param  callable(string, string, string): void  $add
     */
    private function checkDocumentedResources(array $contract, array $document, callable $add): void
    {
        $schemas = (array) ($document['components']['schemas'] ?? []);
        $byResource = [];

        foreach ($schemas as $name => $schema) {
            $class = (string) (($schema['x-resource-class'] ?? ''));
            if ($class !== '') {
                $byResource[$class] = (string) $name;
            }
        }

        foreach ((array) ($contract['documented_resources'] ?? []) as $class => $schemaName) {
            if (! class_exists((string) $class)) {
                $add('resource_class_missing', (string) $class, '文档登记的资源类不存在');

                continue;
            }

            if (! isset($schemas[(string) $schemaName])) {
                $add('resource_schema_missing', (string) $schemaName, 'OpenAPI components.schemas 缺少该 DTO');

                continue;
            }

            if (($byResource[(string) $class] ?? null) !== (string) $schemaName) {
                $add('resource_schema_mismatch', (string) $class, 'schema 的 x-resource-class 未指向该类');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<int, string>
     */
    private function methods(array $spec): array
    {
        return array_values(array_map('strtoupper', (array) ($spec['methods'] ?? [])));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function aclEntries(): array
    {
        $acl = (array) config('acl', []);
        $entries = [];

        $walk = static function (array $node) use (&$walk, &$entries): void {
            foreach ($node as $key => $value) {
                if ($key === 'key' && is_string($value) && isset($node['route'])) {
                    $entries[] = $node;
                }

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($acl);

        return $entries;
    }

    /**
     * @return array<string, true>
     */
    private function aclKeys(): array
    {
        $keys = [];

        foreach ($this->aclEntries() as $entry) {
            $keys[(string) $entry['key']] = true;
        }

        return $keys;
    }

    /**
     * 生态域中会产出稳定错误码的文件。
     *
     * 只扫生态目录，避免把商品 / 支付等其它域的码卷进本契约；
     * 管理后台走异常 + 表单错误，不在此列（后台由 ACL 双向核对覆盖）。
     *
     * @return array<string, string>
     */
    private function errorCodeSources(): array
    {
        $root = base_path('packages/Webkul/Shop/src');
        $patterns = [
            $root.'/Http/Controllers/API/EventController.php',
            $root.'/Http/Controllers/API/EventRegistrationController.php',
            $root.'/Http/Controllers/API/ContentController.php',
            $root.'/Http/Controllers/API/Competition*Controller.php',
            $root.'/Http/Controllers/API/FavoriteController.php',
            $root.'/Http/Controllers/API/EcosystemMediaController.php',
            $root.'/Services/Ecosystem/*.php',
            $root.'/Services/Ecosystem/Scoring/*.php',
            $root.'/Http/Resources/Ecosystem/*.php',
            $root.'/Models/Competition*.php',
            $root.'/Models/Ecosystem*.php',
            $root.'/Models/Event*.php',
        ];

        $sources = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $sources[$file] = (string) file_get_contents($file);
            }
        }

        return $sources;
    }
}
