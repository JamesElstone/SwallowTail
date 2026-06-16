@echo off
setlocal
rem Build with a Visual Studio command prompt:
rem   client\spicebush\build.cmd
cl /nologo /W4 /DWINVER=0x0501 /D_WIN32_WINNT=0x0501 /MT /O2 spicebush_win.c /link /SUBSYSTEM:WINDOWS /OUT:SpiceBush.exe shell32.lib user32.lib gdi32.lib advapi32.lib wininet.lib
