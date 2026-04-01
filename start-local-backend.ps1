[CmdletBinding()]
param(
    [string]$HostName = 'localhost',
    [int]$Port = 8000
)

$phpCommand = Get-Command php -ErrorAction Stop
$phpExe = $phpCommand.Source
$phpRoot = Split-Path -Parent $phpExe
$extensionDir = Join-Path $phpRoot 'ext'

if (-not (Test-Path (Join-Path $extensionDir 'php_pdo_mysql.dll'))) {
    throw "Missing php_pdo_mysql.dll in $extensionDir"
}

if (-not (Test-Path (Join-Path $extensionDir 'php_openssl.dll'))) {
    throw "Missing php_openssl.dll in $extensionDir"
}

$arguments = @(
    "-dextension_dir=$extensionDir",
    '-dextension=openssl',
    '-dextension=pdo_mysql',
    '-S',
    "$HostName`:$Port",
    '-t',
    '.'
)

Write-Host "Starting backend on http://$HostName`:$Port using $phpExe"
& $phpExe @arguments
