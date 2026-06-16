# SpiceBush

SpiceBush finds CR2 RAW image files and uploads them to SwallowTail. The Windows
build is a tray application. The FreeBSD build is a textual command line
program.

The client is intentionally plain C so it can target Windows XP, 7, 10, 11, and
FreeBSD base systems without bundling a runtime. It uses:

- `%APPDATA%\SpiceBush\spicebush.ini` for configuration.
- `~/.spicebush/spicebush.ini` on FreeBSD.
- `%APPDATA%\SpiceBush\queue.tsv` for pending file paths.
- `~/.spicebush/queue.tsv` on FreeBSD.
- `%APPDATA%\SpiceBush\uploaded.tsv` for quick hashes that uploaded or were
  already known by SwallowTail.
- `~/.spicebush/uploaded.tsv` on FreeBSD.
- WinINet/SChannel for Windows HTTP/HTTPS.
- FreeBSD base OpenSSL for FreeBSD HTTP/HTTPS. No ports are required.
- FNV-1a 64-bit quick checksums to match `GET /api/quick-checksum.php`.

## Layout

```text
spicebush_win.c            Windows tray frontend and WinINet transport
spicebush_freebsd.c        FreeBSD textual CLI frontend
spicebush_shared.c/.h      Shared config, scan, hash, JSON, uploaded-cache code
spicebush_http.h           Shared HTTP interface
spicebush_http_freebsd.c   FreeBSD base OpenSSL HTTP/HTTPS transport
build.cmd                  Windows build
Makefile.freebsd           FreeBSD CLI build
```

## Windows Prerequisites

The recommended Windows build uses Microsoft Visual C++ and the Windows SDK:

- Install Visual Studio 2019 or 2022 with the **Desktop development with C++**
  workload, or install the standalone **Build Tools for Visual Studio** with the
  MSVC C++ tools and Windows SDK.
- `build.cmd` looks for `vcvarsall.bat` in common Visual Studio 2019/2022
  locations and loads the x86 build environment automatically.
- The build needs `cl.exe`, `rc.exe`, Windows headers such as `windows.h`, and
  import libraries for `shell32`, `user32`, `gdi32`, `advapi32`, and `wininet`.

The optional GCC build uses MinGW:

- Install a MinGW-w64 or classic MinGW toolchain that provides `gcc` and
  `windres`.
- Put `gcc.exe` and `windres.exe` on `PATH`, or set `CC` and `WINDRES` before
  running `build-gcc.cmd`.
- Use a 32-bit MinGW toolchain that still targets Windows XP if XP support is
  required.

No separate OpenSSL package is needed for the Windows client. HTTPS uses
WinINet/SChannel from Windows.

## Windows Build

Run:

```bat
cd client\spicebush
build.cmd
```

The executable is written to `client\spicebush\work\SpiceBush.exe`.
The build script loads the Visual Studio x86 build environment automatically
when `cl.exe` and `rc.exe` are not already on `PATH`.

With MinGW GCC and `windres` on `PATH`, run:

```bat
cd client\spicebush
build-gcc.cmd
```

That writes `client\spicebush\work\SpiceBush-gcc.exe`. Use a 32-bit MinGW
toolchain that still targets Windows XP if XP compatibility is required.

For older compilers, keep the subsystem as Windows and link with:

```text
shell32.lib user32.lib gdi32.lib advapi32.lib wininet.lib
```

## FreeBSD Build

On FreeBSD:

```sh
cd client/spicebush
make -f Makefile.freebsd
```

This writes `client/spicebush/work/spicebush` and links against base `libssl`
and `libcrypto`.

## Windows First Run

On first launch, SpiceBush creates `%APPDATA%\SpiceBush`, creates
`spicebush.ini`, and opens the `Register with SwallowTail` window. Enter the
SwallowTail site URL, username, password, and OTP code when the account has OTP
enabled. The OTP field may be left empty for accounts without OTP. The client
calls:

```text
POST /api/spicebush-register.php
```

The server returns a bearer upload token and API URL. SpiceBush stores those in
the INI file and uses them for quick-checksum and raw-upload API calls.

## FreeBSD Usage

Register:

```sh
./work/spicebush --register https://swallowtail.example.test user@example.test 'password' 123456
```

If the password or OTP argument is omitted, SpiceBush prompts for it. Press
Enter at the OTP prompt for accounts without OTP enabled. To avoid putting the
password in shell history:

```sh
./work/spicebush --register https://swallowtail.example.test user@example.test
```

Scan one path recursively:

```sh
./work/spicebush --scan /media/camera-card
```

Scan all local mounted filesystems:

```sh
./work/spicebush --scan-existing
```

Watch for new local mount points and scan each new mount up to three folders
deep:

```sh
./work/spicebush --watch 5
```

Show textual statistics for the current run:

```sh
./work/spicebush --stats
```

## Requirement Notes

- The tray process stays idle until Windows reports a new drive letter or the
  user clicks `Scan Existing Drives`.
- The FreeBSD CLI watch mode polls `getmntinfo()` and scans new local mount
  points. This avoids devd integration and keeps the program base-system only.
- New drive scans walk the drive root to a maximum folder depth of three.
- Existing-drive scans recurse through the selected drive. This is intentionally
  available only from the Statistics window because it can take time on large
  disks.
- The local uploaded file stores quick hash plus size. SwallowTail still
  computes and deduplicates the full upload server-side.
- If a SwallowTail account uses MFA or required password change, registration
  should be treated as an interactive policy question. The current registration
  API accepts primary credentials and enforces `upload_tokens` card access.
