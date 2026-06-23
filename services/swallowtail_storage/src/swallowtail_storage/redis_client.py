from __future__ import annotations

import json
import socket
import time
from dataclasses import dataclass


@dataclass(frozen=True)
class RedisConfig:
    host: str = "127.0.0.1"
    port: int = 6379
    timeout_seconds: int = 5
    snapshot_key: str = "swallowtail:storage:snapshot"
    snapshot_ttl_seconds: int = 360
    storage_wake_queue: str = "swallowtail:conversion:storage_wake"


class RedisClient:
    def __init__(self, config: RedisConfig):
        self.config = config

    def set_json(self, key: str, payload: dict, ttl_seconds: int) -> bool:
        json_payload = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
        response = self.command("SET", key, json_payload, "EX", str(max(1, int(ttl_seconds))))
        return self.to_text(response) == "OK"

    def list_push_json(self, key: str, payload: dict, max_length: int = 0) -> bool:
        json_payload = json.dumps(payload, separators=(",", ":"), ensure_ascii=False)
        pushed = isinstance(self.command("LPUSH", key, json_payload), int)
        if pushed and max_length > 0:
            self.command("LTRIM", key, "0", str(max_length - 1))
        return pushed

    def ping(self) -> bool:
        return self.to_text(self.command("PING")) == "PONG"

    def command(self, *parts: str):
        with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
            sock.settimeout(self.config.timeout_seconds)
            sock.sendall(self.encode_command(*parts))
            return self.read_resp(sock)

    def store_snapshot(self, snapshot: dict) -> bool:
        payload = {
            **snapshot,
            "cached_at": int(time.time()),
            "ttl_seconds": self.config.snapshot_ttl_seconds,
        }
        return self.set_json(self.config.snapshot_key, payload, self.config.snapshot_ttl_seconds)

    def encode_command(self, *parts: str) -> bytes:
        encoded = [part.encode("utf-8") for part in parts]
        data = [f"*{len(encoded)}\r\n".encode("ascii")]
        for part in encoded:
            data.append(f"${len(part)}\r\n".encode("ascii"))
            data.append(part + b"\r\n")
        return b"".join(data)

    def read_resp(self, sock: socket.socket):
        marker = self.read_exact(sock, 1)
        if marker == b"*":
            count = int(self.read_line(sock))
            return [self.read_resp(sock) for _ in range(count)]
        if marker == b"$":
            length = int(self.read_line(sock))
            if length < 0:
                return None
            data = self.read_exact(sock, length)
            self.read_exact(sock, 2)
            return data
        if marker == b":":
            return int(self.read_line(sock))
        if marker in {b"+", b"-"}:
            return self.read_line(sock)
        return None

    def read_line(self, sock: socket.socket) -> bytes:
        data = bytearray()
        while not data.endswith(b"\r\n"):
            chunk = sock.recv(1)
            if not chunk:
                break
            data.extend(chunk)
        return bytes(data[:-2])

    def read_exact(self, sock: socket.socket, length: int) -> bytes:
        data = bytearray()
        while len(data) < length:
            chunk = sock.recv(length - len(data))
            if not chunk:
                break
            data.extend(chunk)
        return bytes(data)

    def to_text(self, value) -> str:
        return value.decode("utf-8") if isinstance(value, bytes) else str(value)
