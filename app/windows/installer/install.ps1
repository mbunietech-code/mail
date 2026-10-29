# MbunieMail installer, run by MbunieMail-Setup.exe (see build-installer.ps1).
# Per-user install: no admin prompt, and the folder stays writable so the app can
# update itself (lib/core/updates).
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$dir = Join-Path $env:LOCALAPPDATA 'Programs\MbunieMail'
$exe = Join-Path $dir 'mbuniemail.exe'

# Replace a running copy (including one hidden in the tray).
Get-Process mbuniemail -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Milliseconds 500

New-Item -ItemType Directory -Force -Path $dir | Out-Null
Expand-Archive -Path (Join-Path $here 'app.zip') -DestinationPath $dir -Force
Copy-Item (Join-Path $here 'uninstall.ps1') $dir -Force

# Start menu and desktop shortcuts.
$shell = New-Object -ComObject WScript.Shell
foreach ($folder in @([Environment]::GetFolderPath('Programs'), [Environment]::GetFolderPath('Desktop'))) {
  $lnk = $shell.CreateShortcut((Join-Path $folder 'MbunieMail.lnk'))
  $lnk.TargetPath = $exe
  $lnk.WorkingDirectory = $dir
  $lnk.IconLocation = "$exe,0"
  $lnk.Description = 'MbunieMail'
  $lnk.Save()
}

# Start with Windows, hidden in the tray (the tray menu can turn this off).
$run = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run'
Set-ItemProperty -Path $run -Name 'MbunieMail' -Value "`"$exe`" --background"

# Apps & features entry.
$version = (Get-Item $exe).VersionInfo.ProductVersion
$key = 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\MbunieMail'
New-Item -Path $key -Force | Out-Null
$entry = @{
  DisplayName     = 'MbunieMail'
  DisplayVersion  = $version
  Publisher       = 'MbunieTech'
  DisplayIcon     = "$exe,0"
  InstallLocation = $dir
  UninstallString = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$dir\uninstall.ps1`""
}
foreach ($name in $entry.Keys) { Set-ItemProperty -Path $key -Name $name -Value $entry[$name] }
Set-ItemProperty -Path $key -Name 'NoModify' -Value 1 -Type DWord
Set-ItemProperty -Path $key -Name 'NoRepair' -Value 1 -Type DWord

Start-Process -FilePath $exe -WorkingDirectory $dir
