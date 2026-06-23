from __future__ import annotations

import json
import socket
import time
from dataclasses import dataclass
from datetime import datetime, timezone

from .config import RedisConfig


@dataclass(frozen=True)
class RedisMessage:
    queue: str
    job_id: int
    priority: int = 0
    reason: str = ""


class RedisQueue:
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

    def pop(self) -> RedisMessage | None:
        return self._blocking_pop([self.config.urgent_queue, self.config.normal_queue], self.config.timeout_seconds)

    def pop_storage_wake(self, timeout_seconds: int) -> bool:
        if self.config.storage_wake_queue == "":
            return False

        message = self._blocking_pop([self.config.storage_wake_queue], max(1, int(timeout_seconds)))
        return message is not None

    def _blocking_pop(self, queues: list[str], timeout_seconds: int) -> RedisMessage | None:
        queues = [queue for queue in queues if queue != ""]
        if queues == []:
            return None

        try:
            with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
                sock.settimeout(max(1, int(timeout_seconds)) + 2)
                command = self._command(
                    "BRPOP",
                    *queues,
                    str(max(1, int(timeout_seconds))),
                )
                sock.sendall(command)
                response = self._read_resp(sock)
        except OSError:
            return None

        if not isinstance(response, list) or len(response) != 2:
            return None

        queue = self._to_text(response[0])
        payload = self._to_text(response[1])
        return self._message_from_payload(queue, payload) or RedisMessage(queue=queue, job_id=0)

    def pop_preempt(self) -> RedisMessage | None:
        if self.config.preempt_queue == "":
            return None

        try:
            with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
                sock.settimeout(self.config.timeout_seconds)
                sock.sendall(self._command("RPOP", self.config.preempt_queue))
                response = self._read_resp(sock)
        except OSError:
            return None

        if response is None:
            return None

        return self._message_from_payload(self.config.preempt_queue, self._to_text(response))

    def ping(self) -> None:
        with socket.create_connection((self.config.host, self.config.port), timeout=2) as sock:
            sock.settimeout(self.config.timeout_seconds)
            sock.sendall(self._command("PING"))
            response = self._read_resp(sock)
        if self._to_text(response) != "PONG":
            raise RuntimeError("Redis did not return PONG")

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

    def _message_from_payload(self, queue: str, payload: str) -> RedisMessage | None:
        try:
            data = json.loads(payload)
            job_id = int(data.get("job_id", 0))
            priority = int(data.get("priority", 0) or 0)
            reason = str(data.get("reason", "") or "")
        except (TypeError, ValueError, json.JSONDecodeError):
            return None

        return RedisMessage(queue=queue, job_id=job_id, priority=priority, reason=reason) if job_id > 0 else None
