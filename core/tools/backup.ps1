param(
    [string]$CorePath = (Split-Path -Parent $PSScriptRoot),
    [string]$BackupRoot = (Join-Path $env:USERPROFILE 'POS-Backups'),
    [string]$MySqlBinPath = ''
)

$ErrorActionPreference = 'Stop'
$CorePath = (Resolve-Path -LiteralPath $CorePath).Path
$envPath = Join-Path $CorePath '.env'
if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw 'File .env tidak ditemukan di folder aplikasi.'
}

$config = @{}
foreach ($rawLine in [System.IO.File]::ReadAllLines($envPath)) {
    $line = $rawLine.Trim()
    if (-not $line -or $line.StartsWith('#') -or -not $line.Contains('=')) { continue }
    $parts = $line.Split(@('='), 2, [System.StringSplitOptions]::None)
    $key = $parts[0].Trim()
    $value = $parts[1].Trim()
    if ($value.Length -ge 2 -and $value[0] -eq $value[$value.Length - 1] -and ($value[0] -eq '"' -or $value[0] -eq "'")) {
        $quote = $value[0]
        $value = $value.Substring(1, $value.Length - 2)
        if ($quote -eq '"') { $value = [regex]::Replace($value, '\\(["\\])', '$1') }
    }
    $config[$key] = $value
}

foreach ($required in @('DB_HOST', 'DB_NAME', 'DB_USER')) {
    if (-not $config.ContainsKey($required) -or -not $config[$required]) {
        throw "Konfigurasi $required tidak tersedia pada .env."
    }
}
$hostName = $config['DB_HOST']
$database = $config['DB_NAME']
$userName = $config['DB_USER']
$password = [string]$config['DB_PASS']
$port = if ($config.ContainsKey('DB_PORT') -and $config['DB_PORT']) { $config['DB_PORT'] } else { '3306' }
if ($database -notmatch '^[A-Za-z0-9_$-]{1,64}$') { throw 'DB_NAME harus berupa nama database MySQL yang sederhana.' }
if ($port -notmatch '^\d{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) { throw 'DB_PORT tidak valid.' }
if (@($hostName, $database, $userName, $password, $port) -match "[`r`n]") {
    throw 'Konfigurasi database mengandung baris baru yang tidak didukung.'
}

if (-not $MySqlBinPath) {
    $installRoot = Split-Path -Parent (Split-Path -Parent $CorePath)
    $MySqlBinPath = Join-Path $installRoot 'mysql\bin'
}
$dumpExe = Join-Path $MySqlBinPath 'mysqldump.exe'
if (-not (Test-Path -LiteralPath $dumpExe -PathType Leaf)) {
    throw "mysqldump.exe tidak ditemukan. Isi MySqlBinPath saat menjalankan skrip."
}

$null = New-Item -ItemType Directory -Path $BackupRoot -Force
$BackupRoot = (Resolve-Path -LiteralPath $BackupRoot).Path
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupPath = Join-Path $BackupRoot ('pos-backup-' + $stamp)
$null = New-Item -ItemType Directory -Path $backupPath
$sqlPath = Join-Path $backupPath 'database.sql'
$optionPath = [System.IO.Path]::GetTempFileName()

try {
    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = Get-Acl -LiteralPath $optionPath
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($identity)
    $rule = New-Object -TypeName System.Security.AccessControl.FileSystemAccessRule -ArgumentList @(
        $identity, [System.Security.AccessControl.FileSystemRights]::FullControl,
        [System.Security.AccessControl.AccessControlType]::Allow
    )
    $acl.AddAccessRule($rule)
    Set-Acl -LiteralPath $optionPath -AclObject $acl

    $escapeOption = {
        param([string]$value)
        if ($value -match "[`r`n]") { throw 'Nilai konfigurasi tidak boleh berisi baris baru.' }
        return '"' + $value.Replace('\', '\\').Replace('"', '\"') + '"'
    }
    $optionText = "[client]`r`nhost=$(& $escapeOption $hostName)`r`nport=$port`r`nuser=$(& $escapeOption $userName)`r`npassword=$(& $escapeOption $password)`r`ndefault-character-set=utf8mb4`r`n"
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($optionPath, $optionText, $utf8NoBom)

    $arguments = '--defaults-extra-file="' + $optionPath + '" --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 "' + $database + '"'
    $start = New-Object System.Diagnostics.ProcessStartInfo
    $start.FileName = $dumpExe
    $start.Arguments = $arguments
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'mysqldump tidak dapat dijalankan.' }
    $stderrTask = $process.StandardError.ReadToEndAsync()
    $output = [System.IO.File]::Open($sqlPath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
    try { $process.StandardOutput.BaseStream.CopyTo($output) } finally { $output.Dispose() }
    $process.WaitForExit()
    $stderr = $stderrTask.GetAwaiter().GetResult()
    if ($process.ExitCode -ne 0) {
        throw "Backup database gagal (exit $($process.ExitCode)): $stderr"
    }
    if ((Get-Item -LiteralPath $sqlPath).Length -eq 0) { throw 'mysqldump menghasilkan file kosong.' }
    $sqlHash = (Get-FileHash -LiteralPath $sqlPath -Algorithm SHA256).Hash.ToLowerInvariant()

    $uploadsPath = Join-Path $CorePath 'storage\uploads'
    if (Test-Path -LiteralPath $uploadsPath -PathType Container) {
        Copy-Item -LiteralPath $uploadsPath -Destination (Join-Path $backupPath 'uploads') -Recurse
    } else {
        $null = New-Item -ItemType Directory -Path (Join-Path $backupPath 'uploads')
    }
    $manifest = @(
        'Backup aplikasi POS',
        ('Dibuat: ' + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz')),
        ('Database: ' + $database),
        ('SHA-256 database.sql: ' + $sqlHash),
        'Termasuk: dump seluruh database dan storage/uploads.',
        'Tidak termasuk: file aplikasi, .env, log, cache, dan sesi login.',
        'Simpan salinan di lokasi aman yang terpisah dari server ini.'
    ) -join "`r`n"
    [System.IO.File]::WriteAllText((Join-Path $backupPath 'README.txt'), $manifest, [System.Text.Encoding]::UTF8)
    Write-Output "Backup selesai: $backupPath"
} catch {
    if (Test-Path -LiteralPath $backupPath -PathType Container) {
        Remove-Item -LiteralPath $backupPath -Recurse -Force
    }
    throw
} finally {
    if (Test-Path -LiteralPath $optionPath -PathType Leaf) {
        Remove-Item -LiteralPath $optionPath -Force
    }
}
