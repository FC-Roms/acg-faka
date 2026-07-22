param(
    [switch]$Staged
)

$ErrorActionPreference = 'Stop'
$repoCandidate = Split-Path -Parent $PSScriptRoot
$repoOutput = git -C $repoCandidate rev-parse --show-toplevel

if ($LASTEXITCODE -ne 0 -or -not $repoOutput) {
    Write-Error '当前目录不是 Git 仓库。'
    exit 2
}

$repo = $repoOutput.Trim()
Set-Location -LiteralPath $repo

if ($Staged) {
    $files = @(git -c core.quotepath=false diff --cached --name-only --diff-filter=ACM)
} else {
    $files = @(git -c core.quotepath=false ls-files)
}

$textExtensions = @(
    '.php', '.js', '.json', '.md', '.txt', '.yml', '.yaml', '.ini', '.conf',
    '.html', '.sql', '.sh', '.ps1', '.xml', '.toml'
)

$knownSecretPatterns = @(
    'gh[pousr]_[A-Za-z0-9]{30,}',
    'AKIA[0-9A-Z]{16}',
    'AIza[0-9A-Za-z_-]{30,}',
    'sk_live_[0-9A-Za-z]{16,}',
    'xox[baprs]-[0-9A-Za-z-]{10,}',
    'eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}',
    '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'
)

$protectedConfigPaths = @(
    'config/database.php',
    'config/coupon_api.php',
    'app/Plugin/ApiNotification/Config/Config.php'
)

$sensitiveAssignment = @'
(?i)["']?(password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|accesskeysecret|authorization|webhook|private[_-]?key)["']?\s*(?:=>|:|=)\s*["']([^"']+)["']
'@
$allowedPlaceholder = '(?i)^(EXAMPLE|REPLACE|CHANGEME|YOUR_|XXX|TEST|DEMO|\$|\{\{)'
$findings = New-Object System.Collections.Generic.List[string]

foreach ($file in $files) {
    if (-not $file -or $file -like 'vendor/*') {
        continue
    }

    $extension = [IO.Path]::GetExtension($file).ToLowerInvariant()
    $isSpecialTextFile = $file -in @('Dockerfile', '.dockerignore', '.gitignore')
    if ($extension -notin $textExtensions -and -not $isSpecialTextFile) {
        continue
    }

    if ($Staged) {
        $content = (git show ":$file" 2>$null | Out-String)
    } else {
        $path = Join-Path $repo $file
        if (-not (Test-Path -LiteralPath $path)) {
            continue
        }
        $content = Get-Content -LiteralPath $path -Raw -Encoding UTF8
    }

    foreach ($pattern in $knownSecretPatterns) {
        if ([regex]::IsMatch($content, $pattern)) {
            $findings.Add("$file：命中已知密钥格式")
            break
        }
    }

    if ($content -match '(?i)gamec\.jp') {
        $findings.Add("$file：包含已脱敏的私有 API 域名")
    }

    if ($file -in $protectedConfigPaths) {
        foreach ($match in [regex]::Matches($content, $sensitiveAssignment)) {
            $value = $match.Groups[2].Value.Trim()
            if ($value -and $value -notmatch $allowedPlaceholder) {
                $findings.Add("$file：字段 $($match.Groups[1].Value) 包含非空字面量")
            }
        }
    }
}

$sensitiveScreenshot = Join-Path $repo 'app/Plugin/ApiNotification/Wiki/Images/Menu.png'
$originalScreenshotHash = 'D2C8DF9AFA0EAA57205328B4D95568B0C6B905CFC7145786D4A96873327173A4'
if (Test-Path -LiteralPath $sensitiveScreenshot) {
    $currentHash = (Get-FileHash -LiteralPath $sensitiveScreenshot -Algorithm SHA256).Hash
    if ($currentHash -eq $originalScreenshotHash) {
        $findings.Add('ApiNotification/Wiki/Images/Menu.png：检测到脱敏前原图')
    }
}

if ($findings.Count -gt 0) {
    Write-Host '公开仓库隐私检查失败：' -ForegroundColor Red
    $findings | Sort-Object -Unique | ForEach-Object { Write-Host "- $_" -ForegroundColor Red }
    exit 1
}

Write-Host "公开仓库隐私检查通过，共检查 $($files.Count) 个 tracked/staged 文件。" -ForegroundColor Green
