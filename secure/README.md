# secure

This folder sits outside `web_root` and is used for local security files that must not be web-accessible, such as `app.php`, generated keys, and first-user bootstrap codes.

Do not serve this directory publicly. Keep secret files in this folder out of version control and restrict filesystem permissions in production.

The PHP process must be able to create and update `app.php`, and create and remove local setup files in this
directory. On FreeBSD, PHP commonly runs as the `www` user. The conversion
service reads database settings from `app.php`, so the file should be owned by
`www:swallowtail`:

```bash
chown root:www secure
chmod 775 secure
chown www:swallowtail secure/app.php
chmod 0640 secure/app.php
```

If first-user setup reports that `bootstrap_code.txt` could not be created,
check that the PHP user has write permission to this directory.
