@echo off
setlocal
rem Build with a Visual Studio command prompt:
rem   client\spicebush\build.cmd
pushd "%~dp0"
if not exist work mkdir work
cl /nologo /W4 /DWINVER=0x0501 /D_WIN32_WINNT=0x0501 /MT /O2 spicebush_win.c /link /SUBSYSTEM:WINDOWS /OUT:work\SpiceBush.exe shell32.lib user32.lib gdi32.lib advapi32.lib wininet.lib
popd
