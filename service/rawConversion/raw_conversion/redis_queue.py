from __future__ import annotations

import json
import socket
from dataclasses import dataclass

from .config import RedisConfig


@dataclass(frozen=True)
class RedisMessage:
    queue: str
    job_id: int


class RedisQueue:
    def __init__(self, config: RedisConfig):
        self.config = config

    def pop(self) -> RedisMessage | None:
        try:
            with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
                sock.settimeout(self.config.timeout_seconds + 2)
                command = self._command(
                    "BRPOP",
                    self.config.urgent_queue,
                    self.config.normal_queue,
                    str(self.config.timeout_seconds),
                )
                sock.sendall(command)
                response = self._read_resp(sock)
        except OSError:
            return None

        if not isinstance(response, list) or len(response) != 2:
            return None

        queue = self._to_text(response[0])
        payload = self._to_text(response[1])
        try:
            data = json.loads(payload)
            job_id = int(data.get("job_id", 0))
        except (TypeError, ValueError, json.JSONDecodeError):
            return None

        return RedisMessage(queue=queue, job_id=job_id) if job_id > 0 else None

    def _command(self, *parts: str) -> bytes:
        encoded = [part.encode("utf-8") for part in parts]
        data = [f"*{len(encoded)}\r\n".encode("ascii")]
        for part in encoded:
            data.append(f"${len(part)}\r\n".encode("ascii"))
            data.append(part + b"\r\n")
        return b"".join(data)

    def _read_resp(self, sock: socket.socket):
        marker = self._read_exact(sock, 1)
        if marker == b"*":
            count = int(self._read_line(sock))
            return [self._read_resp(sock) for _ in range(count)]
        if marker == b"$":
            length = int(self._read_line(sock))
            if length < 0:
                return None
            data = self._read_exact(sock, length)
            self._read_exact(sock, 2)
            return data
        if marker == b":":
            return int(self._read_line(sock))
        if marker in {b"+", b"-"}:
            return self._read_line(sock)
        return None

    def _read_line(self, sock: socket.socket) -> bytes:
        data = bytearray()
        while not data.endswith(b"\r\n"):
            chunk = sock.recv(1)
            if not chunk:
                break
            data.extend(chunk)
        return bytes(data[:-2])

    def _read_exact(self, sock: socket.socket, length: int) -> bytes:
        data = bytearray()
        while len(data) < length:
            chunk = sock.recv(length - len(data))
            if not chunk:
                break
            data.extend(chunk)
        return bytes(data)

    def _to_text(self, value) -> str:
        return value.decode("utf-8") if isinstance(value, bytes) else str(value)
