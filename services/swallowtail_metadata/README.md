# SwallowTail Metadata Worker

Extracts EXIF and Canon MakerNote metadata from stored CR2 files and writes
`photo_metadata` rows.

On FreeBSD the rc.d wrapper runs the worker under `daemon(8)` supervision and
restarts it after a crash. The restart delay is controlled by
`swallowtail_metadata_restart_delay_seconds`.

```sh
python3.11 -m swallowtail_metadata --health
python3.11 -m swallowtail_metadata --status
python3.11 -m swallowtail_metadata --once
```
