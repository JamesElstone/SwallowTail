# SwallowTail Metadata Worker

Extracts EXIF and Canon MakerNote metadata from stored CR2 files and writes
`photo_metadata` rows.

```sh
python3.11 -m swallowtail_metadata --health
python3.11 -m swallowtail_metadata --status
python3.11 -m swallowtail_metadata --once
```
