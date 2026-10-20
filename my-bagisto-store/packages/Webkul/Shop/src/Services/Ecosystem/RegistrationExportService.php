<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Shop\Models\EcosystemExportFile;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2-13 · 专用报名导出。
 *
 * 与通用 DataGrid 导出完全分开：
 *   · 文件只落私有盘，路径不进前端；
 *   · 下载 token 只存 SHA-256，默认 10 分钟过期；
 *   · 默认遮罩；原始联系人需同时具备 export + pii 活动能力；
 *   · 下载时重新校验权限与活动范围，权限撤回后不能新下载；
 *   · CSV 单元格中和公式注入。
 */
class RegistrationExportService
{
    public const COLUMNS = [
        'public_no', 'attempt_no', 'status', 'name', 'phone', 'city',
        'submitted_at', 'decided_at', 'canceled_at', 'cancel_reason_code', 'decision_reason_code',
    ];

    public function __construct(
        private readonly RegistrationStaffScope $staff,
        private readonly RegistrationContactVault $vault,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @param  array{status?: string|null}  $filters
     * @return array{token: string, expires_at: string, filename: string, rows: int}
     */
    public function create(int $eventId, Admin $admin, array $filters, bool $includePii): array
    {
        $this->staff->assertCapability($eventId, 'export', $admin);

        if ($includePii) {
            $this->staff->assertCapability($eventId, 'pii', $admin);
        }

        $status = (string) ($filters['status'] ?? '');

        $query = EventRegistrationAttempt::query()
            ->with('registration')
            ->where('event_id', $eventId)
            ->orderBy('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        $maxRows = max(1, (int) config('ecosystem_registration.export.max_rows', 20000));
        $total = (clone $query)->count();

        if ($total > $maxRows) {
            throw RegistrationException::invalid(sprintf('导出超过上限（%d 行），请先缩小筛选范围。', $maxRows));
        }

        $rows = [];
        $rows[] = self::COLUMNS;

        foreach ($query->cursor() as $attempt) {
            /** @var EventRegistrationAttempt $attempt */
            $registration = $attempt->registration;
            $name = $this->vault->decryptName((string) $attempt->contact_name_enc);
            $phone = $this->vault->decryptPhone((string) $attempt->contact_phone_enc);

            if (! $includePii) {
                $name = $this->vault->maskName($name);
                $phone = $this->vault->maskPhone($phone);
            }

            $rows[] = [
                (string) ($registration?->public_no ?? ''),
                (string) $attempt->attempt_no,
                (string) $attempt->status,
                $name,
                $phone,
                (string) ($attempt->contact_city ?? ''),
                $this->iso($attempt->submitted_at),
                $this->iso($attempt->decided_at),
                $this->iso($attempt->canceled_at),
                (string) ($attempt->cancel_reason_code ?? ''),
                (string) ($attempt->decision_reason_code ?? ''),
            ];
        }

        $csv = "\xEF\xBB\xBF".$this->toCsv($rows);
        $filename = sprintf('registrations-event-%d-%s.csv', $eventId, now('UTC')->format('YmdHis'));
        $path = trim((string) config('ecosystem_registration.export.directory', 'ecosystem/registration-exports'), '/')
            .'/'.Str::random(40).'.csv';
        $disk = (string) config('ecosystem_registration.export.disk', 'local');

        Storage::disk($disk)->put($path, $csv);

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = now('UTC')->addSeconds((int) config('ecosystem_registration.export.ttl_seconds', 600));

        EcosystemExportFile::query()->create([
            'token_hash' => hash('sha256', $token),
            'event_id'   => $eventId,
            'admin_id'   => (int) $admin->getKey(),
            'filters'    => $filters,
            'fields'     => self::COLUMNS,
            'file_path'  => $path,
            'expires_at' => $expiresAt,
        ]);

        $this->logger->logOrFail(
            'admin',
            (int) $admin->getKey(),
            $includePii ? 'registration.export.pii' : 'registration.export.masked',
            'event',
            $eventId,
            ['rows' => count($rows) - 1, 'fields' => self::COLUMNS],
        );

        return [
            'token'      => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'filename'   => $filename,
            'rows'       => count($rows) - 1,
        ];
    }

    /**
     * 下载前重新校验：过期、撤销、权限/活动范围变化都会阻止。
     *
     * @return array{path: string, filename: string, disk: string}
     */
    public function download(string $token, Admin $admin): array
    {
        $file = EcosystemExportFile::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($file === null || $file->revoked_at !== null) {
            throw new RegistrationException('EXPORT_EXPIRED', 410, '导出文件不存在或已被撤销。', 'export_revoked');
        }

        if ($file->expires_at === null || $file->expires_at->lessThan(now('UTC'))) {
            throw new RegistrationException('EXPORT_EXPIRED', 410, '导出文件已过期。', 'export_expired');
        }

        // 重新校验活动范围与导出能力；权限撤回后不能新下载。
        $this->staff->assertCapability((int) $file->event_id, 'export', $admin);

        if ((int) $file->admin_id !== (int) $admin->getKey()
            && ! $this->staff->isSuperAdmin($admin)) {
            throw new RegistrationException('EXPORT_FORBIDDEN', 403, '该导出文件不属于当前账号。', 'export_owner');
        }

        $disk = (string) config('ecosystem_registration.export.disk', 'local');

        if (! Storage::disk($disk)->exists((string) $file->file_path)) {
            throw new RegistrationException('EXPORT_EXPIRED', 410, '导出文件已不可用。', 'export_missing');
        }

        $file->downloaded_at = now('UTC');
        $file->save();

        $this->logger->logOrFail(
            'admin',
            (int) $admin->getKey(),
            'registration.export.downloaded',
            'ecosystem_export_file',
            (int) $file->getKey(),
            ['event_id' => (int) $file->event_id],
        );

        return [
            'path'     => (string) $file->file_path,
            'filename' => sprintf(
                'registrations-event-%d-%s.csv',
                (int) $file->event_id,
                $file->created_at !== null
                    ? \Illuminate\Support\Carbon::parse($file->created_at)->format('YmdHis')
                    : now('UTC')->format('YmdHis'),
            ),
            'disk'     => $disk,
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $value): string => $this->neutralize($value), $row));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * CSV 公式注入中和：= + - @ 开头，以及 tab/CR 开头的单元格前置单引号。
     */
    private function neutralize(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], ' ', $value);

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function iso(mixed $value): string
    {
        if (! $value instanceof \Illuminate\Support\Carbon) {
            return '';
        }

        return $value->copy()
            ->timezone((string) config('app.timezone', 'Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
    }
}
