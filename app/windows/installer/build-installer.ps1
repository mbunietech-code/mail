# Packages a Windows release build for distribution, using IExpress (part of Windows):
#   dist\MbunieMail-<version>+<build>.exe          installer for new PCs (double-click)
#   dist\MbunieMail-<version>+<build>-windows.zip  what installed apps update themselves from
# Upload both to backend/public/downloads. Run after:
#   flutter build windows --release --build-number=<n> --dart-define=API_BASE_URL=https://email.mbunie.tech
param(
  [string]$Release = (Join-Path $PSScriptRoot '..\..\build\windows\x64\runner\Release'),
  [string]$Out = (Join-Path $PSScriptRoot '..\..\dist')
)
$ErrorActionPreference = 'Stop'
$Release = (Resolve-Path $Release).Path
$exe = Join-Path $Release 'mbuniemail.exe'
if (-not (Test-Path $exe)) { throw "No release build at $Release" }
$version = (Get-Item $exe).VersionInfo.ProductVersion   # e.g. 1.0.0+58
if ($version -notmatch '^\d+\.\d+\.\d+\+\d+$') { throw "Build with --build-number (version is '$version')" }

New-Item -ItemType Directory -Force -Path $Out | Out-Null
$Out = (Resolve-Path $Out).Path
# No "setup" or "install" in the name: Windows would then ask for admin rights,
# and this is a per-user install.
$zip = Join-Path $Out "MbunieMail-$version-windows.zip"
$installer = Join-Path $Out "MbunieMail-$version.exe"
Remove-Item $zip, $installer -ErrorAction SilentlyContinue
Compress-Archive -Path (Join-Path $Release '*') -DestinationPath $zip

$stage = Join-Path ([IO.Path]::GetTempPath()) "MbunieMail-installer-$PID"
New-Item -ItemType Directory -Force -Path $stage | Out-Null
try {
  Copy-Item $zip (Join-Path $stage 'app.zip')
  Copy-Item (Join-Path $PSScriptRoot 'install.ps1'), (Join-Path $PSScriptRoot 'uninstall.ps1') $stage
  Set-Content -Path (Join-Path $stage 'install.cmd') -Encoding Ascii -Value `
    '@powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0install.ps1" > "%TEMP%\MbunieMail-install.log" 2>&1'

  $sed = Join-Path $stage 'installer.sed'
  Set-Content -Path $sed -Encoding Ascii -Value @"
[Version]
Class=IEXPRESS
SEDVersion=3
[Options]
PackagePurpose=InstallApp
ShowInstallProgramWindow=0
HideExtractAnimation=0
UseLongFileName=1
InsideCompressed=0
CAB_FixedSize=0
CAB_ResvCodeSigning=0
RebootMode=N
InstallPrompt=%InstallPrompt%
DisplayLicense=%DisplayLicense%
FinishMessage=%FinishMessage%
TargetName=%TargetName%
FriendlyName=%FriendlyName%
AppLaunched=%AppLaunched%
PostInstallCmd=%PostInstallCmd%
AdminQuietInstCmd=%AdminQuietInstCmd%
UserQuietInstCmd=%UserQuietInstCmd%
SourceFiles=SourceFiles
[Strings]
InstallPrompt=Install MbunieMail $version?
DisplayLicense=
FinishMessage=MbunieMail is installed. It starts with Windows and lives in the tray next to the clock.
TargetName=$installer
FriendlyName=MbunieMail
AppLaunched=install.cmd
PostInstallCmd=<None>
AdminQuietInstCmd=install.cmd
UserQuietInstCmd=install.cmd
FILE0="app.zip"
FILE1="install.ps1"
FILE2="uninstall.ps1"
FILE3="install.cmd"
[SourceFiles]
SourceFiles0=$stage\
[SourceFiles0]
%FILE0%=
%FILE1%=
%FILE2%=
%FILE3%=
"@
  $p = Start-Process -FilePath "$env:WINDIR\System32\iexpress.exe" -ArgumentList '/N', '/Q', $sed -Wait -PassThru
  if ($p.ExitCode -ne 0 -or -not (Test-Path $installer)) { throw "IExpress failed ($($p.ExitCode))" }
} finally {
  Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
}

Get-Item $installer, $zip | Select-Object Name, Length
