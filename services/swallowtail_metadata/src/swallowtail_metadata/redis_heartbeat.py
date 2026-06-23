from __future__ import annotations

import json
import socket
import time
from datetime import datetime, timezone

from .config import RedisConfig


class RedisHeartbeat:
    def __init__(self, config: RedisConfig):
        self.config = config

    def touch_service(self, service_key: str) -> bool:
        heartbeat_key = f"swallowtail:service:{service_key}:last_touched"
        touched_at = int(time.time())
        payload = json.dumps(
            {
                "service": service_key,
                "touched_at": touched_at,
                "touched_at_iso": datetime.fromtimestamp(touched_at, timezone.utc).isoformat(),
            },
            separators=(",", ":"),
        )
        try:
            with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
                sock.settimeout(self.config.timeout_seconds)
                sock.sendall(self._command("SET", heartbeat_key, payload, "EX", "720"))
                response = self._read_resp(sock)
        except OSError:
            return False
        return self._to_text(response) == "OK"

    def ping(self) -> None:
        with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
            sock.settimeout(self.config.timeout_seconds)
            sock.sendall(self._command("PING"))
            response = self._read_resp(sock)
        if self._to_text(response) != "PONG":
            raise RuntimeError("Redis did not return PONG")

    def _command(self, *parts: str) -> bytes:
        encoded = [part.encode("utf-8") for part in parts]
        payload = f"*{len(encoded)}\r\n".encode("ascii")
        for part in encoded:
            payload += f"${len(part)}\r\n".encode("ascii") + part + b"\r\n"
        return payload

    def _read_resp(self, sock) -> object:
        prefix = sock.recv(1)
        if prefix == b"+":
            return self._read_line(sock).decode("utf-8", "replace")
        if prefix == b"-":
            raise RuntimeError(self._read_line(sock).decode("utf-8", "replace"))
        if prefix == b":":
            return int(self._read_line(sock))
        if prefix == b"$":
            length = int(self._read_line(sock))
            if length < 0:
                return None
            data = self._read_exact(sock, length)
            self._read_exact(sock, 2)
            return data
        if prefix == b"*":
            count = int(self._read_line(sock))
            return [self._read_resp(sock) for _ in range(count)]
        raise RuntimeError("Unexpected Redis response")

    def _read_line(self, sock) -> bytes:
        data = b""
        while not data.endswith(b"\r\n"):
            chunk = sock.recv(1)
            if not chunk:
                raise RuntimeError("Redis connection closed")
            data += chunk
        return data[:-2]

    def _read_exact(self, sock, length: int) -> bytes:
        data = b""
        while len(data) < length:
            chunk = sock.recv(length - len(data))
            if not chunk:
                raise RuntimeError("Redis connection closed")
            data += chunk
        return data

    def _to_text(self, value: object) -> str:
        if isinstance(value, bytes):
            return value.decode("utf-8", "replace")
        return "" if value is None else str(value)
