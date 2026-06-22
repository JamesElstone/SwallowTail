@echo off
setlocal
rem Build with a Visual Studio command prompt:
rem   client\spicebush\build.cmd
pushd "%~dp0"
call :ensure_msvc
if errorlevel 1 (
    popd
    exit /b 1
)
if not exist work mkdir work
rc /nologo /fowork\spicebush.res spicebush.rc
if errorlevel 1 (
    popd
    exit /b 1
)
cl /nologo /W4 /D_CRT_SECURE_NO_WARNINGS /DWINVER=0x0501 /D_WIN32_WINNT=0x0501 /MT /O2 /Fowork\ spicebush_win.c spicebush_shared.c work\spicebush.res /link /SUBSYSTEM:WINDOWS /OUT:work\SpiceBush.exe shell32.lib user32.lib gdi32.lib advapi32.lib wininet.lib
set BUILD_STATUS=%ERRORLEVEL%
if "%BUILD_STATUS%"=="0" (
    del /q work\spicebush_win.obj work\spicebush_shared.obj work\spicebush.res >nul 2>nul
)
popd
exit /b %BUILD_STATUS%

:ensure_msvc
where cl.exe >nul 2>nul
if not errorlevel 1 where rc.exe >nul 2>nul
if not errorlevel 1 exit /b 0

set "VCVARSALL="
set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
if exist "%VSWHERE%" (
    for /f "usebackq delims=" %%I in (`"%VSWHERE%" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do (
        if exist "%%I\VC\Auxiliary\Build\vcvarsall.bat" (
            set "VCVARSALL=%%I\VC\Auxiliary\Build\vcvarsall.bat"
            goto found_vcvarsall
        )
    )
)

for %%P in (
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2022\Enterprise\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2022\Professional\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2022\BuildTools\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2019\Enterprise\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2019\Professional\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2019\Community\VC\Auxiliary\Build\vcvarsall.bat"
    "%ProgramFiles(x86)%\Microsoft Visual Studio\2019\BuildTools\VC\Auxiliary\Build\vcvarsall.bat"
) do (
    if exist %%~P (
        set "VCVARSALL=%%~P"
        goto found_vcvarsall
    )
)

:found_vcvarsall
if "%VCVARSALL%"=="" (
    echo Could not find Visual Studio vcvarsall.bat. Install Visual Studio C++ Build Tools or run this from a Developer Command Prompt.
    exit /b 1
)

call "%VCVARSALL%" x86
if errorlevel 1 exit /b 1
where cl.exe >nul 2>nul
if errorlevel 1 (
    echo cl.exe was not found after loading the Visual Studio build environment.
    exit /b 1
)
where rc.exe >nul 2>nul
if errorlevel 1 (
    echo rc.exe was not found after loading the Visual Studio build environment.
    exit /b 1
)
exit /b 0
