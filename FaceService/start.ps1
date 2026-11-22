$ErrorActionPreference = "Stop"
$serviceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$venvPython = Join-Path $serviceRoot ".venv\Scripts\python.exe"
$serviceRunner = Join-Path $serviceRoot "run_service.py"
$tokenPath = Join-Path $serviceRoot ".service-token"

if (-not (Test-Path $venvPython) -or -not (Test-Path $serviceRunner)) {
    throw "Python environment is missing. Run FaceService\install.ps1 first."
}

if (-not (Test-Path $tokenPath)) {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    $token = [Convert]::ToBase64String($bytes).TrimEnd("=").Replace("+", "-").Replace("/", "_")
    [System.IO.File]::WriteAllText($tokenPath, $token, [System.Text.Encoding]::ASCII)
}

$env:PYTHONUTF8 = "1"
$env:PYTHONIOENCODING = "utf-8"
& $venvPython $serviceRunner
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
