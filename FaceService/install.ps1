$ErrorActionPreference = "Stop"
$serviceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$python = Get-Command python -ErrorAction Stop
$venvPython = Join-Path $serviceRoot ".venv\Scripts\python.exe"

if (-not (Test-Path $venvPython)) {
    & $python.Source -m venv (Join-Path $serviceRoot ".venv")
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

& $venvPython -m pip install -r (Join-Path $serviceRoot "requirements.txt")
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
