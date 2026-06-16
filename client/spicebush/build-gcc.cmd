@echo off
setlocal
pushd "%~dp0"

if not defined CC set "CC=gcc"
if not defined WINDRES set "WINDRES=windres"

where "%CC%" >nul 2>nul
if errorlevel 1 (
    echo Could not find %CC%. Install a 32-bit MinGW GCC toolchain or set CC to the compiler path.
    popd
    exit /b 1
)

where "%WINDRES%" >nul 2>nul
if errorlevel 1 (
    echo Could not find %WINDRES%. Install MinGW windres or set WINDRES to the resource compiler path.
    popd
    exit /b 1
)

if not exist work mkdir work
"%WINDRES%" -O coff -i spicebush.rc -o work\spicebush-gcc.res
if errorlevel 1 (
    popd
    exit /b 1
)

"%CC%" -Wall -Wextra -Os -std=c99 -DWINVER=0x0501 -D_WIN32_WINNT=0x0501 -mwindows -o work\SpiceBush-gcc.exe spicebush_win.c work\spicebush-gcc.res -lshell32 -luser32 -lgdi32 -ladvapi32 -lwininet
set BUILD_STATUS=%ERRORLEVEL%
if "%BUILD_STATUS%"=="0" (
    del /q work\spicebush-gcc.res >nul 2>nul
)
popd
exit /b %BUILD_STATUS%
