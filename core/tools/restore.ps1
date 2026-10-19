param(
    [Parameter(Mandatory = $true)][string]$BackupPath,
    [Parameter(Mandatory = $true)][string]$ConfirmDatabase,
    [string]$CorePath = (Split-Path -Parent $PSScriptRoot),
    [string]$BackupRoot = (Join-Path $env:USERPROFILE 'POS-Backups'),
    [string]$MySqlBinPath = ''
)

$ErrorActionPreference = 'Stop'
$CorePath = (Resolve-Path -LiteralPath $CorePath).Path
$backupItem = Get-Item -LiteralPath $BackupPath
$backupDirectory = if ($backupItem.PSIsContainer) { $backupItem.FullName } else { $backupItem.DirectoryName }
$sqlPath = if ($backupItem.PSIsContainer) { Join-Path $backupDirectory 'database.sql' } else { $backupItem.FullName }
$backupUploads = Join-Path $backupDirectory 'uploads'
if (-not (Test-Path -LiteralPath $sqlPath -PathType Leaf) -or -not (Test-Path -LiteralPath $backupUploads -PathType Container)) {
    throw 'Backup harus berisi database.sql dan folder uploads.'
}

$config = @{}
foreach ($rawLine in [System.IO.File]::ReadAllLines((Join-Path $CorePath '.env'))) {
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
$database = $config['DB_NAME']
if ($database -notmatch '^[A-Za-z0-9_$-]{1,64}$') { throw 'DB_NAME harus berupa nama database MySQL yang sederhana.' }
if ($ConfirmDatabase -cne $database) {
    throw "Pemulihan dibatalkan. Jalankan ulang dan isi -ConfirmDatabase `"$database`" untuk mengonfirmasi penggantian database."
}
$manifestPath = Join-Path $backupDirectory 'README.txt'
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) { throw 'README.txt backup tidak ditemukan.' }
$manifestLines = [System.IO.File]::ReadAllLines($manifestPath)
$databaseLine = $manifestLines | Where-Object { $_ -like 'Database: *' } | Select-Object -First 1
if (-not $databaseLine -or ($databaseLine.Substring('Database: '.Length) -cne $database)) {
    throw 'Nama database di backup tidak sama dengan DB_NAME aplikasi.'
}
$hashLine = $manifestLines | Where-Object { $_ -like 'SHA-256 database.sql: *' } | Select-Object -First 1
$expectedHash = if ($hashLine) { ($hashLine -split ': ', 2)[1].Trim().ToLowerInvariant() } else { '' }
$actualHash = (Get-FileHash -LiteralPath $sqlPath -Algorithm SHA256).Hash.ToLowerInvariant()
if (-not $expectedHash -or $expectedHash -ne $actualHash) {
    throw 'Hash database.sql tidak cocok. Backup rusak atau bukan hasil tools/backup.ps1.'
}

if (-not $MySqlBinPath) {
    $installRoot = Split-Path -Parent (Split-Path -Parent $CorePath)
    $MySqlBinPath = Join-Path $installRoot 'mysql\bin'
}
$mysqlExe = Join-Path $MySqlBinPath 'mysql.exe'
if (-not (Test-Path -LiteralPath $mysqlExe -PathType Leaf)) {
    throw 'mysql.exe tidak ditemukan. Isi MySqlBinPath saat menjalankan skrip.'
}

$backupScript = Join-Path $PSScriptRoot 'backup.ps1'
& $backupScript -CorePath $CorePath -BackupRoot $BackupRoot -MySqlBinPath $MySqlBinPath
if (-not $?) { throw 'Backup kondisi saat ini gagal; database tidak dipulihkan.' }

$storagePath = Join-Path $CorePath 'storage'
$uploadsPath = Join-Path $storagePath 'uploads'
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$stagedUploads = Join-Path $storagePath ('.uploads-restore-' + $stamp)
$preservedUploads = Join-Path $storagePath ('uploads-before-restore-' + $stamp)
$optionPath = [System.IO.Path]::GetTempFileName()
$movedOldUploads = $false

try {
    Copy-Item -LiteralPath $backupUploads -Destination $stagedUploads -Recurse
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
    $hostName = $config['DB_HOST']
    $userName = $config['DB_USER']
    $password = [string]$config['DB_PASS']
    $port = if ($config.ContainsKey('DB_PORT') -and $config['DB_PORT']) { $config['DB_PORT'] } else { '3306' }
    if ($port -notmatch '^\d{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) { throw 'DB_PORT tidak valid.' }
    $optionText = "[client]`r`nhost=$(& $escapeOption $hostName)`r`nport=$port`r`nuser=$(& $escapeOption $userName)`r`npassword=$(& $escapeOption $password)`r`ndefault-character-set=utf8mb4`r`n"
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($optionPath, $optionText, $utf8NoBom)

    $start = New-Object System.Diagnostics.ProcessStartInfo
    $start.FileName = $mysqlExe
    $start.Arguments = '--defaults-extra-file="' + $optionPath + '" --default-character-set=utf8mb4 "' + $database + '"'
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardError = $true
    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'mysql.exe tidak dapat dijalankan.' }
    $stderrTask = $process.StandardError.ReadToEndAsync()
    $input = [System.IO.File]::OpenRead($sqlPath)
    try { $input.CopyTo($process.StandardInput.BaseStream) } finally {
        $input.Dispose()
        $process.StandardInput.Close()
    }
    $process.WaitForExit()
    $stderr = $stderrTask.GetAwaiter().GetResult()
    if ($process.ExitCode -ne 0) {
        throw "Restore database gagal (exit $($process.ExitCode)): $stderr"
    }

    if (Test-Path -LiteralPath $uploadsPath -PathType Container) {
        Move-Item -LiteralPath $uploadsPath -Destination $preservedUploads
        $movedOldUploads = $true
    }
    Move-Item -LiteralPath $stagedUploads -Destination $uploadsPath
    Write-Output 'Pemulihan database dan uploads selesai.'
    if ($movedOldUploads) {
        Write-Output "Uploads sebelum pemulihan disimpan di: $preservedUploads"
    }
} catch {
    if ($movedOldUploads -and -not (Test-Path -LiteralPath $uploadsPath) -and (Test-Path -LiteralPath $preservedUploads)) {
        Move-Item -LiteralPath $preservedUploads -Destination $uploadsPath
    }
    throw
} finally {
    if (Test-Path -LiteralPath $optionPath -PathType Leaf) {
        Remove-Item -LiteralPath $optionPath -Force
    }
}
