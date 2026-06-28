from __future__ import annotations

import hashlib
import logging
import os
import shutil
import subprocess
import uuid
from dataclasses import replace
from pathlib import Path
from typing import Any, Callable, NamedTuple

from .config import StorageConfig
from .jobs import ConversionJob


class StorageBlocked(RuntimeError):
    pass


class StorageLocation(NamedTuple):
    base: str
    total_bytes: int
    free_bytes: int


class ConversionStorageManager:
    DATA_DIRECTORY = "swallowtail-data"
    IMAGE_EXTENSIONS = {
        "source": ".cr2",
        "source_profile": ".pp3",
        "embedded": ".jpg",
        "thumbnail": ".jpg",
        "thumbnail_profile": ".pp3",
        "preview": ".jpg",
        "preview_profile": ".pp3",
        "original": ".jpg",
        "final": ".jpg",
        "final_profile": ".pp3",
        "rawtherapee_sample": ".jpg",
    }

    def __init__(
        self,
        config: StorageConfig,
        db,
        disk_usage: Callable[[str], shutil._ntuple_diskusage] = shutil.disk_usage,
        mount_reader: Callable[[], list[str]] | None = None,
        zfs_reader: Callable[[], dict[str, dict[str, str]]] | None = None,
    ):
        self.config = config
        self.db = db
        self.disk_usage = disk_usage
        self.mount_reader = mount_reader or self._mounted_base_locations
        self.zfs_reader = zfs_reader or self._zfs_datasets_by_mountpoint
        self.log = logging.getLogger("swallowtail_conversion.storage")

    def has_usable_location(self) -> bool:
        return self.usable_locations() != []

    def usable_locations(self) -> list[StorageLocation]:
        return [location for location in self.candidate_locations() if self._location_has_headroom(location)]

    def location_has_headroom(self, base: str) -> bool:
        try:
            usage = self.disk_usage(self._normalise_directory(base))
        except OSError:
            return False
        return usage.total > 0 and usage.free > self._threshold_bytes(usage.total)

    def relocate_job_if_needed(self, job: ConversionJob) -> ConversionJob:
        photo = self.db.photo_storage(job.photo_id)
        if photo is None:
            return job

        checksum = str(photo.get("original_sha256") or "").strip().lower()
        old_base = self._normalise_directory(str(photo.get("storage_base_location") or ""))
        if checksum == "" or old_base == "":
            return job

        if self.location_is_usable(old_base):
            return job

        destination = self._destination_for_relocation(old_base)
        if destination is None:
            raise StorageBlocked("No storage location is above the configured free-space threshold.")

        new_base = destination.base
        try:
            copied_pairs = self._copy_checksum_family(old_base, new_base, checksum)
        except PermissionError as exc:
            raise StorageBlocked(f"Storage location is not writable: {new_base}") from exc
        old_root = self.data_root(old_base)
        new_root = self.data_root(new_base)

        self.db.update_photo_storage_location(job.photo_id, old_base, new_base, old_root, new_root)
        old_free_bytes, old_threshold_bytes, reason = self._relocation_reason(old_base)
        copied_files = ",".join(destination.name for _source, destination in copied_pairs)
        self.log.info(
            "Relocated storage before conversion job=%s photo=%s checksum=%s old_base=%s new_base=%s "
            "reason=%s old_free_bytes=%s old_threshold_bytes=%s new_free_bytes=%s copied_files=%s",
            job.id,
            job.photo_id,
            checksum,
            old_base,
            new_base,
            reason,
            old_free_bytes,
            old_threshold_bytes,
            destination.free_bytes,
            copied_files,
        )

        for source, _destination in copied_pairs:
            try:
                source.unlink()
            except OSError:
                pass

        return replace(
            job,
            input_path=job.input_path.replace(old_root, new_root),
            output_path=job.output_path.replace(old_root, new_root),
            profile_path=job.profile_path.replace(old_root, new_root) if job.profile_path else None,
        )

    def data_root(self, base: str) -> str:
        return str(Path(self._normalise_directory(base)) / self.DATA_DIRECTORY) + os.sep

    def image_path(self, base: str, checksum: str, image_type: str) -> Path:
        checksum = checksum.lower()
        extension = self.IMAGE_EXTENSIONS[image_type]
        suffix = {
            "source_profile": "source",
            "thumbnail_profile": "thumbnail",
            "preview_profile": "preview",
            "final_profile": "final",
        }.get(image_type, image_type)
        return (
            Path(self.data_root(base))
            / checksum[0:2]
            / checksum[2:4]
            / f"{checksum}_{suffix}{extension}"
        )

    def candidate_locations(self) -> list[StorageLocation]:
        properties = self._properties_by_key()
        zfs_by_mount = self.zfs_reader()
        selected_zfs = self._selected_zfs_datasets(zfs_by_mount, properties)
        candidates = list(self.mount_reader())

        for row in self._property_rows():
            if self._bool(row.get("is_zfs")):
                continue
            base = str(row.get("storage_base_location") or "").strip()
            if base != "":
                candidates.append(base)

        locations: list[StorageLocation] = []
        seen: set[str] = set()
        for candidate in candidates:
            try:
                base = self._normalise_directory(candidate)
            except ValueError:
                continue
            if base in seen or (not self.config.store_on_root_partition and self._is_root_location(base)):
                continue
            seen.add(base)

            dataset = zfs_by_mount.get(base)
            property_key = str(dataset.get("zpool_name") or "") if dataset else base
            property_row = properties.get(property_key, {})
            if self._bool(property_row.get("is_excluded")):
                continue
            if dataset:
                selected = selected_zfs.get(str(dataset.get("zpool_name") or ""))
                if str(selected.get("dataset_name") or "") != str(dataset.get("dataset_name") or ""):
                    continue

            try:
                usage = self.disk_usage(base)
            except OSError:
                continue
            if usage.total <= 0:
                continue
            locations.append(StorageLocation(base=base, total_bytes=int(usage.total), free_bytes=int(usage.free)))

        return sorted(locations, key=lambda location: location.base)

    def location_is_usable(self, base: str) -> bool:
        try:
            base = self._normalise_directory(base)
            usage = self.disk_usage(base)
        except (OSError, ValueError):
            return False

        return (
            usage.total > 0
            and usage.free > self._threshold_bytes(usage.total)
            and self._location_accepts_writes(base)
        )

    def _destination_for_relocation(self, old_base: str) -> StorageLocation | None:
        old_base = self._normalise_directory(old_base)
        for location in self.usable_locations():
            if location.base != old_base:
                return location
        return None

    def _copy_checksum_family(self, old_base: str, new_base: str, checksum: str) -> list[tuple[Path, Path]]:
        copied_pairs: list[tuple[Path, Path]] = []
        try:
            for image_type in self.IMAGE_EXTENSIONS:
                source = self.image_path(old_base, checksum, image_type)
                if not source.is_file():
                    continue
                destination = self.image_path(new_base, checksum, image_type)
                self._copy_verified(source, destination)
                copied_pairs.append((source, destination))
            return copied_pairs
        except Exception:
            for _source, destination in copied_pairs:
                try:
                    destination.unlink()
                except OSError:
                    pass
            raise

    def _copy_verified(self, source: Path, destination: Path) -> None:
        destination.parent.mkdir(parents=True, exist_ok=True)
        if destination.is_file():
            if source.stat().st_size == destination.stat().st_size and self._sha256(source) == self._sha256(destination):
                return
            raise RuntimeError(f"Destination storage file already exists with different content: {destination}")

        temporary = destination.with_name(f".{destination.name}.moving-{uuid.uuid4().hex}")
        shutil.copy2(source, temporary)
        if source.stat().st_size != temporary.stat().st_size or self._sha256(source) != self._sha256(temporary):
            try:
                temporary.unlink()
            finally:
                raise RuntimeError(f"Storage relocation verification failed: {source}")
        os.replace(temporary, destination)

    def _location_has_headroom(self, location: StorageLocation) -> bool:
        return (
            location.free_bytes > self._threshold_bytes(location.total_bytes)
            and self._location_accepts_writes(location.base)
        )

    def _location_accepts_writes(self, base: str) -> bool:
        data_root = Path(self.data_root(base))
        if data_root.is_dir():
            return os.access(data_root, os.W_OK | os.X_OK)

        parent = data_root.parent
        while not parent.exists() and parent != parent.parent:
            parent = parent.parent
        if not parent.is_dir():
            return False

        return os.access(parent, os.W_OK | os.X_OK)

    def _threshold_bytes(self, total_bytes: int) -> float:
        return float(total_bytes) * (self.config.full_threshold_percent / 100.0)

    def _relocation_reason(self, base: str) -> tuple[int | None, int | None, str]:
        try:
            usage = self.disk_usage(self._normalise_directory(base))
        except OSError:
            return None, None, "old_storage_unavailable"

        threshold = int(self._threshold_bytes(int(usage.total)))
        if int(usage.free) <= threshold:
            return int(usage.free), threshold, "old_storage_below_free_space_threshold"
        return int(usage.free), threshold, "old_storage_not_writable"

    def _property_rows(self) -> list[dict[str, Any]]:
        if not hasattr(self.db, "storage_location_properties"):
            return []
        return list(self.db.storage_location_properties())

    def _properties_by_key(self) -> dict[str, dict[str, Any]]:
        properties: dict[str, dict[str, Any]] = {}
        for row in self._property_rows():
            is_zfs = self._bool(row.get("is_zfs"))
            key = str(row.get("storage_base_location") or "").strip() if is_zfs else self._normalise_directory(
                str(row.get("storage_base_location") or "")
            )
            if key != "":
                properties[key] = row
        return properties

    def _selected_zfs_datasets(
        self,
        zfs_by_mount: dict[str, dict[str, str]],
        properties: dict[str, dict[str, Any]],
    ) -> dict[str, dict[str, str]]:
        by_pool: dict[str, list[dict[str, str]]] = {}
        for dataset in zfs_by_mount.values():
            pool = str(dataset.get("zpool_name") or "")
            if pool != "":
                by_pool.setdefault(pool, []).append(dataset)

        selected: dict[str, dict[str, str]] = {}
        for pool, datasets in by_pool.items():
            datasets = sorted(datasets, key=lambda item: str(item.get("dataset_name") or ""))
            configured = str((properties.get(pool) or {}).get("dataset_name") or "").strip()
            chosen = datasets[0] if datasets else {}
            for dataset in datasets:
                if configured != "" and str(dataset.get("dataset_name") or "") == configured:
                    chosen = dataset
                    break
            if chosen:
                selected[pool] = chosen
        return selected

    def _mounted_base_locations(self) -> list[str]:
        if os.name == "nt":
            drive = Path(self.config.project_root).anchor
            return [drive] if drive else [self.config.project_root]

        try:
            result = subprocess.run(["/bin/df", "-Pk"], check=False, capture_output=True, text=True, timeout=10)
        except (OSError, subprocess.TimeoutExpired):
            return [str(Path(self.config.project_root).parent)]

        lines = [line for line in result.stdout.splitlines()[1:] if line.strip()]
        mounts = []
        for line in lines:
            columns = line.split()
            if len(columns) >= 6 and not columns[-1].startswith("/dev"):
                mounts.append(columns[-1])
        return mounts or [str(Path(self.config.project_root).parent)]

    def _zfs_datasets_by_mountpoint(self) -> dict[str, dict[str, str]]:
        if os.name == "nt":
            return {}

        try:
            result = subprocess.run(
                ["zfs", "list", "-H", "-p", "-o", "name,mountpoint"],
                check=False,
                capture_output=True,
                text=True,
                timeout=10,
            )
        except (OSError, subprocess.TimeoutExpired):
            return {}

        datasets: dict[str, dict[str, str]] = {}
        for line in result.stdout.splitlines():
            columns = line.split(maxsplit=1)
            if len(columns) < 2:
                continue
            dataset_name = columns[0].strip()
            mountpoint = columns[1].strip()
            if dataset_name == "" or mountpoint in {"", "-", "none", "legacy"}:
                continue
            mount = self._normalise_directory(mountpoint)
            datasets[mount] = {
                "dataset_name": dataset_name,
                "zpool_name": dataset_name.split("/", 1)[0],
                "mountpoint": mount,
            }
        return datasets

    def _normalise_directory(self, path: str) -> str:
        path = str(path).strip()
        if path == "":
            raise ValueError("Storage base location must not be empty.")
        normalised = os.path.abspath(path)
        return normalised.rstrip(os.sep) + os.sep

    def _is_root_location(self, path: str) -> bool:
        return Path(path).anchor == path

    @staticmethod
    def _bool(value: Any) -> bool:
        if isinstance(value, bool):
            return value
        if isinstance(value, (int, float)):
            return value != 0
        if isinstance(value, str):
            return value.strip().lower() in {"1", "true", "yes", "on"}
        return False

    @staticmethod
    def _sha256(path: Path) -> str:
        digest = hashlib.sha256()
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
