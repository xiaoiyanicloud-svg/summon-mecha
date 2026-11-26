<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 源包（开发者上传的 ZIP）安全读取器 —— B4。
 *
 * 为什么单独成类：源包是**外部不可信输入**，而它此前是在编译器内部随手 `ZipArchive::getFromName`
 * 读的 —— 校验散落在业务代码里，改一处漏一处。所有"能不能读、读多少、读出来能不能信"
 * 的判断现在只在这里，编译路径只消费结果。
 *
 * 四类威胁逐条封堵（每类都有对应的拒绝码，不合并成一个"源包非法"）：
 *
 *   1. **ZIP Slip / 路径穿越**：条目名出现 `..`、绝对路径、盘符、反斜杠、控制字符、
 *      空段，一律拒绝。当前编译器不把源包解压到磁盘（只按名取内容），但产物包会下发到设备端
 *      由 Agent 解压 —— 一条 `../../etc/passwd` 若被原样带进产物，平台就成了投毒工具。
 *      所以**入口名称校验做在读取处**，产物打包处再做一次纵深防御（见 TargetAppBuilder）。
 *   2. **软链接条目**：ZIP 的 external attributes 可把条目标记成 symlink。解压时它会变成
 *      指向宿主任意路径的链接，后续写入即穿越。按 Unix 文件类型位（`0120000`）拒绝；
 *      非 Unix（OPSYS_DOS 等）不产生链接语义，不做类型判断（避免误杀 Windows 打包的正常文件）。
 *   3. **压缩炸弹**：同时用**声明大小**（central directory）与**实际流式读取字节数**两道闸。
 *      只信声明大小是不够的：声明可以造假，而 `getFromIndex()` 会按声明一次性分配内存。
 *      故改用 `getStream()` 分块读，超过单条/总预算立刻中止 —— 读到一半停，不会 OOM。
 *      另加压缩比闸：只对**解压后 > 1 MiB** 的条目生效，避免把合法的静音 WAV（压缩比天然极高）误杀。
 *   4. **保留名冒用**：`manifest.json` / `artifact.json` 是**平台生成**的文件。源包里出现它们，
 *      就意味着开发者想顶替产物清单或构建身份（`artifact.json` 里有 build_key / source_digest）。
 *      `manifest.json` 历史夹具里就有（无害，跳过）；`artifact.json` 直接拒绝。
 *
 * ⚠ 预算值全部走 `config('skill_build.source_package.*')`，但**默认是收紧的**：
 *   安全上限不是功能开关，"默认关闭"在这里等于"默认不设防"，因此默认值即为生效值，
 *   只在确有需要时通过 env 放大。
 */
class SourcePackageReader
{
    /** 拒绝码 —— 与 `SourcePackageRejected::$reasonCode` 一一对应。 */
    public const CODE_UNREADABLE = 'SOURCE_PACKAGE_UNREADABLE';

    public const CODE_INVALID_ARCHIVE = 'SOURCE_PACKAGE_INVALID_ARCHIVE';

    public const CODE_ARCHIVE_TOO_LARGE = 'SOURCE_PACKAGE_ARCHIVE_TOO_LARGE';

    public const CODE_TOO_MANY_ENTRIES = 'SOURCE_PACKAGE_TOO_MANY_ENTRIES';

    public const CODE_UNSAFE_ENTRY = 'SOURCE_PACKAGE_UNSAFE_ENTRY';

    public const CODE_SYMLINK_ENTRY = 'SOURCE_PACKAGE_SYMLINK_ENTRY';

    public const CODE_ENCRYPTED_ENTRY = 'SOURCE_PACKAGE_ENCRYPTED_ENTRY';

    public const CODE_RESERVED_ENTRY = 'SOURCE_PACKAGE_RESERVED_ENTRY';

    public const CODE_ENTRY_TOO_LARGE = 'SOURCE_PACKAGE_ENTRY_TOO_LARGE';

    public const CODE_TOTAL_TOO_LARGE = 'SOURCE_PACKAGE_TOTAL_TOO_LARGE';

    public const CODE_BOMB = 'SOURCE_PACKAGE_BOMB';

    public const CODE_ENTRYPOINT_MISSING = 'SOURCE_PACKAGE_ENTRYPOINT_MISSING';

    public const CODE_ENTRYPOINT_INVALID = 'SOURCE_PACKAGE_ENTRYPOINT_INVALID';

    public const CODE_READ_FAILED = 'SOURCE_PACKAGE_READ_FAILED';

    /** 源包里出现即视为冒用产物元数据（`manifest.json` 属历史夹具，跳过不拒）。 */
    public const RESERVED_OUTPUT_NAMES = ['artifact.json'];

    /**
     * 读取源包入口 XML 与全部素材。
     *
     * @return array{xml_content: string, assets: array<string, string>, entries: array<int, array{name: string, size: int}>}
     *
     * @throws SourcePackageRejected
     */
    public function read(string $archivePath, string $entrypoint): array
    {
        $caps = $this->caps();

        if (! is_file($archivePath) || ! is_readable($archivePath)) {
            throw new SourcePackageRejected(self::CODE_UNREADABLE, '源包文件不存在或不可读。');
        }

        $archiveSize = @filesize($archivePath);
        if ($archiveSize === false) {
            throw new SourcePackageRejected(self::CODE_UNREADABLE, '无法读取源包大小。');
        }

        if ($archiveSize > $caps['max_archive_bytes']) {
            throw new SourcePackageRejected(
                self::CODE_ARCHIVE_TOO_LARGE,
                "源包压缩体积 {$archiveSize} 字节超过上限 {$caps['max_archive_bytes']} 字节。"
            );
        }

        if (! class_exists(\ZipArchive::class)) {
            throw new SourcePackageRejected(self::CODE_UNREADABLE, '服务器缺少 ZipArchive 扩展，无法读取源包。');
        }

        if (! $this->isSafeRelativePath($entrypoint)) {
            throw new SourcePackageRejected(
                self::CODE_ENTRYPOINT_INVALID,
                "源包入口路径非法：'{$entrypoint}'（禁止绝对路径 / 盘符 / 反斜杠 / `..` / 控制字符）。"
            );
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new SourcePackageRejected(self::CODE_INVALID_ARCHIVE, '无法打开源包（不是有效的 ZIP）。');
        }

        try {
            return $this->readEntries($zip, $entrypoint, $caps);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, int>  $caps
     * @return array{xml_content: string, assets: array<string, string>, entries: array<int, array{name: string, size: int}>}
     */
    protected function readEntries(\ZipArchive $zip, string $entrypoint, array $caps): array
    {
        $numFiles = (int) $zip->numFiles;

        if ($numFiles > $caps['max_entries']) {
            throw new SourcePackageRejected(
                self::CODE_TOO_MANY_ENTRIES,
                "源包条目数 {$numFiles} 超过上限 {$caps['max_entries']}。"
            );
        }

        $assets = [];
        $entries = [];
        $xmlContent = null;
        $declaredTotal = 0;
        $actualTotal = 0;

        for ($i = 0; $i < $numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            // 1. 路径穿越/绝对路径/控制字符 —— 目录条目同样要过这一关（目录名也可能带 `..`）。
            if (! $this->isSafeRelativePath($name)) {
                throw new SourcePackageRejected(
                    self::CODE_UNSAFE_ENTRY,
                    "源包条目路径非法：'{$name}'（禁止绝对路径 / 盘符 / 反斜杠 / `..` / 控制字符）。"
                );
            }

            // 2. 软链接条目：解压后会变成指向宿主路径的链接。
            if ($this->isSymlinkEntry($zip, $i)) {
                throw new SourcePackageRejected(
                    self::CODE_SYMLINK_ENTRY,
                    "源包含软链接条目：'{$name}'（禁止，解压后会指向包外路径）。"
                );
            }

            // 3. 加密条目：平台不持有源包口令，读出来也无法校验，直接拒绝。
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new SourcePackageRejected(self::CODE_READ_FAILED, "无法读取源包条目信息：'{$name}'。");
            }

            if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
                throw new SourcePackageRejected(self::CODE_ENCRYPTED_ENTRY, "源包含加密条目：'{$name}'（禁止）。");
            }

            // 目录条目到此为止：路径已校验，没有内容可读。
            if (str_ends_with($name, '/')) {
                continue;
            }

            // 4. 保留名冒用。
            if (in_array($name, self::RESERVED_OUTPUT_NAMES, true)) {
                throw new SourcePackageRejected(
                    self::CODE_RESERVED_ENTRY,
                    "源包含平台保留文件 '{$name}'（该文件由编译产物生成，源包不得提供）。"
                );
            }

            // `manifest.json` 是历史夹具的一部分（源清单），平台不把它带进产物，跳过而非拒绝。
            if ($name === 'manifest.json') {
                continue;
            }

            $declaredSize = (int) ($stat['size'] ?? 0);
            if ($declaredSize < 0) {
                throw new SourcePackageRejected(self::CODE_INVALID_ARCHIVE, "源包条目 '{$name}' 声明大小非法。");
            }

            $declaredTotal += $declaredSize;
            if ($declaredTotal > $caps['max_total_uncompressed_bytes']) {
                throw new SourcePackageRejected(
                    self::CODE_TOTAL_TOO_LARGE,
                    "源包解压后声明总大小 {$declaredTotal} 字节超过上限 {$caps['max_total_uncompressed_bytes']} 字节。"
                );
            }

            $bytes = $this->readEntryBytes($zip, $name, $caps, $actualTotal, (int) ($stat['comp_size'] ?? 0));
            $actualTotal += strlen($bytes);

            $entries[] = ['name' => $name, 'size' => strlen($bytes)];

            if ($name === $entrypoint) {
                $xmlContent = $bytes;
                continue;
            }

            $assets[$name] = $bytes;
        }

        if ($xmlContent === null || $xmlContent === '') {
            throw new SourcePackageRejected(
                self::CODE_ENTRYPOINT_MISSING,
                "源包缺少入口文件 '{$entrypoint}'，或入口内容为空。"
            );
        }

        return ['xml_content' => $xmlContent, 'assets' => $assets, 'entries' => $entries];
    }

    /**
     * 流式读取单个条目，**按实际字节**而非声明大小设闸（炸弹防线）。
     *
     * @param  array<string, int>  $caps
     */
    protected function readEntryBytes(\ZipArchive $zip, string $name, array $caps, int $alreadyRead, int $compressedSize): string
    {
        $stream = $zip->getStream($name);

        if (! is_resource($stream)) {
            // 加密/损坏集中在这里暴露：getStream 返回 false。
            throw new SourcePackageRejected(self::CODE_READ_FAILED, "无法读取源包条目 '{$name}'（加密或损坏）。");
        }

        $buffer = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    throw new SourcePackageRejected(self::CODE_READ_FAILED, "读取源包条目 '{$name}' 时出错。");
                }

                if ($chunk === '') {
                    continue;
                }

                $buffer .= $chunk;

                if (strlen($buffer) > $caps['max_entry_bytes']) {
                    throw new SourcePackageRejected(
                        self::CODE_ENTRY_TOO_LARGE,
                        "源包条目 '{$name}' 解压后超过单条上限 {$caps['max_entry_bytes']} 字节。"
                    );
                }

                if ($alreadyRead + strlen($buffer) > $caps['max_total_uncompressed_bytes']) {
                    throw new SourcePackageRejected(
                        self::CODE_TOTAL_TOO_LARGE,
                        "源包解压后总大小超过上限 {$caps['max_total_uncompressed_bytes']} 字节。"
                    );
                }
            }
        } finally {
            fclose($stream);
        }

        // 压缩比闸：只对足够大的条目生效 —— 静音 WAV 这类合法素材的压缩比本来就极高，
        // 一刀切会把正常素材误判成炸弹。
        $actual = strlen($buffer);
        if ($actual > $caps['ratio_check_threshold_bytes'] && $compressedSize > 0) {
            if (intdiv($actual, $compressedSize) > $caps['max_compression_ratio']) {
                throw new SourcePackageRejected(
                    self::CODE_BOMB,
                    "源包条目 '{$name}' 压缩比异常（解压 {$actual} / 压缩 {$compressedSize}），疑似压缩炸弹。"
                );
            }
        }

        return $buffer;
    }

    /**
     * 条目是否为符号链接（按 Unix 文件类型位判断；非 Unix 打包不判类型，避免误杀）。
     */
    protected function isSymlinkEntry(\ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        // OPSYS_UNIX = 3；只有 Unix 的属性里才带 st_mode。
        if ($opsys !== \ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $type = ($attributes >> 16) & 0170000;

        return $type === 0120000;
    }

    /**
     * 条目名是否安全的**相对路径**。
     *
     * 逐条明确禁止：空串、控制字符（含 NUL）、反斜杠、绝对路径、盘符、`..`/`.`/空段、超长。
     * 目录条目允许以 `/` 结尾，但尾斜杠之前的部分同样要过段级校验。
     */
    public function isSafeRelativePath(string $name): bool
    {
        if ($name === '' || strlen($name) > 512) {
            return false;
        }

        // 控制字符（含 NUL、换行）在文件名里没有任何正当用途。
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false;
        }

        if (str_contains($name, '\\')) {
            return false;
        }

        if (str_starts_with($name, '/')) {
            return false;
        }

        // Windows 盘符（C:/ 或 C:）。
        if (preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        $trimmed = rtrim($name, '/');
        if ($trimmed === '') {
            return false;
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            if (strlen($segment) > 255) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    protected function caps(): array
    {
        $cfg = (array) config('skill_build.source_package', []);

        return [
            'max_archive_bytes'            => (int) ($cfg['max_archive_bytes'] ?? 67108864),
            'max_entries'                  => (int) ($cfg['max_entries'] ?? 256),
            'max_entry_bytes'              => (int) ($cfg['max_entry_bytes'] ?? 16777216),
            'max_total_uncompressed_bytes' => (int) ($cfg['max_total_uncompressed_bytes'] ?? 67108864),
            'max_compression_ratio'        => (int) ($cfg['max_compression_ratio'] ?? 100),
            'ratio_check_threshold_bytes'  => (int) ($cfg['ratio_check_threshold_bytes'] ?? 1048576),
        ];
    }
}
