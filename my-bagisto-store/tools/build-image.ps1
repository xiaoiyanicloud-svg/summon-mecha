# 独立构建镜像摘要探针（WBS B5.5 / 第十批 T5）
#
# 做三件事：
#   1. 确认 Docker daemon 可用（不可用就明确失败，不伪造摘要）；
#   2. 真实构建镜像；
#   3. 从 `docker image inspect` 读出**真实内容摘要**，写成可被平台读取的环境变量文件。
#
# 为什么优先 `.Id` 而不是 `.RepoDigests`：RepoDigest 只有 push 到 registry 之后才存在，
# 本地 build 出来的镜像 `.RepoDigests` 是空数组。`.Id` 是镜像配置对象的内容摘要（sha256:...），
# 同样是 inspect 得到的真实值 —— 用它不算伪造。有 RepoDigest 时优先用 RepoDigest。
#
# 用法（Docker Desktop 已启动）：
#   powershell -NoProfile -ExecutionPolicy Bypass -File tools\build-image.ps1
# 然后（当前会话）：
#   . .\docker\build\image-digest.env.ps1
#   D:\php.exe vendor\bin\pest packages\Webkul\Shop\tests\Feature\Deploy\ToolchainFingerprintTest.php
#
# 退出码：0 = 摘要已取得；2 = daemon 不可用；3 = 构建失败；4 = 取不到合法摘要。

$ErrorActionPreference = "Continue"

$repoRoot = Split-Path -Parent $PSScriptRoot
$imageRef = "summon-mecha/skill-build:local"
$outFile = Join-Path $repoRoot "docker\build\image-digest.env.ps1"

Push-Location $repoRoot
try {
    $server = (& docker info --format '{{.ServerVersion}}' 2>$null)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($server)) {
        Write-Error "Docker daemon 不可用（docker info 失败）。请先启动 Docker Desktop。"
        exit 2
    }
    Write-Output "docker server: $server"

    & docker build -f docker/build/Dockerfile -t $imageRef .
    if ($LASTEXITCODE -ne 0) {
        Write-Error "docker build 失败（exit=$LASTEXITCODE）。"
        exit 3
    }

    $repoDigest = (& docker image inspect --format '{{index .RepoDigests 0}}' $imageRef 2>$null)
    $digest = $null
    if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace($repoDigest)) {
        $digest = ($repoDigest -split '@')[-1]
    }

    if (-not $digest -or $digest -notmatch '^sha256:[0-9a-f]{64}$') {
        # 本地镜像：RepoDigest 为空，退回 .Id（同样是 inspect 出来的真实内容摘要）
        $digest = (& docker image inspect --format '{{.Id}}' $imageRef 2>$null)
        if ($LASTEXITCODE -ne 0) { $digest = $null }
    }

    if (-not $digest -or $digest -notmatch '^sha256:[0-9a-f]{64}$') {
        Write-Error "取不到合法镜像摘要（得到: '$digest'）。拒绝伪造 digest。"
        exit 4
    }

    @(
        "`$env:SKILL_BUILD_IMAGE_REF = '$imageRef'"
        "`$env:SKILL_BUILD_IMAGE_DIGEST = '$digest'"
    ) | Out-File -FilePath $outFile -Encoding utf8

    Write-Output "image_ref=$imageRef"
    Write-Output "image_digest=$digest"
    Write-Output "written: $outFile"
    exit 0
}
finally {
    Pop-Location
}
