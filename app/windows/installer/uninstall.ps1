# Removes MbunieMail (Apps & features → Uninstall). Mail stays on the server; the
# sign-in is kept in Windows Credential Manager until the user signs out.
$dir = Join-Path $env:LOCALAPPDATA 'Programs\MbunieMail'

Get-Process mbuniemail -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Milliseconds 500

Remove-ItemProperty -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Run' -Name 'MbunieMail' -ErrorAction SilentlyContinue
Remove-Item -Path 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\MbunieMail' -Recurse -ErrorAction SilentlyContinue
foreach ($folder in @([Environment]::GetFolderPath('Programs'), [Environment]::GetFolderPath('Desktop'))) {
  Remove-Item (Join-Path $folder 'MbunieMail.lnk') -ErrorAction SilentlyContinue
}
Remove-Item (Join-Path $env:APPDATA 'MbunieMail\startup-default-applied') -ErrorAction SilentlyContinue

# This script lives in $dir, so delete the folder after it exits.
Start-Process -WindowStyle Hidden cmd.exe -ArgumentList '/c', "timeout /t 2 /nobreak >nul & rmdir /s /q `"$dir`""
