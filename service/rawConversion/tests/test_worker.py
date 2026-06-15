from __future__ import annotations

import unittest
import shutil
from pathlib import Path

from raw_conversion.config import RawTherapeeConfig
from raw_conversion.jobs import ConversionJob
from raw_conversion.rawtherapee import RawTherapeeRunner


class RawTherapeeRunnerTest(unittest.TestCase):
    def test_runner_writes_output_with_fake_binary(self) -> None:
        root = Path.cwd() / "tmp-test-worker"
        if root.exists():
            shutil.rmtree(root)
        root.mkdir(parents=True)
        try:
            input_path = root / "test.CR2"
            input_path.write_bytes(b"II*\0CR2")
            output_path = root / "final.jpg"
            fake = Path(__file__).parent / "fixtures" / "fake_rawtherapee.py"
            job = ConversionJob(
                id=1,
                photo_id=2,
                derivative_type="preview",
                input_path=str(input_path),
                pp3_path=None,
                output_path=str(output_path),
                output_storage_path="derivatives/preview/aa/bb/test_preview.jpg",
                output_storage_location_id=3,
                profile_version=1,
                attempts=0,
            )

            result = RawTherapeeRunner(
                RawTherapeeConfig(binary=str(fake), maximum_threads=1, home=str(root / "home"))
            ).render(job, str(root / "work"))

            self.assertEqual(0, result.exit_code)
            self.assertTrue(Path(result.temp_output_path).is_file())
            self.assertIn("-c", result.command)
            self.assertIn(str(input_path), result.command)
        finally:
            shutil.rmtree(root, ignore_errors=True)


if __name__ == "__main__":
    unittest.main()
