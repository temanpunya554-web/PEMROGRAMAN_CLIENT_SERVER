import asyncio
import secrets
import time
from pathlib import Path

import uvicorn

from app import app


IDLE_SHUTDOWN_SECONDS = 300
FACE_ENDPOINTS = {"/v1/enroll", "/v1/verify"}
TOKEN_PATH = Path(__file__).with_name(".service-token")

app.state.active_face_requests = 0
app.state.last_request_at = time.monotonic()
app.state.shutdown_handle = None
app.state.uvicorn_server = None


def stop_if_idle() -> None:
    if app.state.active_face_requests == 0 and app.state.uvicorn_server is not None:
        app.state.uvicorn_server.should_exit = True


@app.middleware("http")
async def stop_after_face_request(request, call_next):
    app.state.last_request_at = time.monotonic()
    if request.url.path not in FACE_ENDPOINTS:
        return await call_next(request)

    loop = asyncio.get_running_loop()
    app.state.active_face_requests += 1
    if app.state.shutdown_handle is not None:
        app.state.shutdown_handle.cancel()
        app.state.shutdown_handle = None

    try:
        return await call_next(request)
    finally:
        app.state.active_face_requests -= 1
        if app.state.active_face_requests == 0:
            app.state.shutdown_handle = loop.call_later(3, stop_if_idle)


async def stop_after_abandoned_challenge() -> None:
    while True:
        await asyncio.sleep(10)
        if (
            time.monotonic() - app.state.last_request_at >= IDLE_SHUTDOWN_SECONDS
            and app.state.active_face_requests == 0
        ):
            stop_if_idle()
            return


@app.on_event("startup")
async def start_idle_shutdown_watchdog() -> None:
    app.state.last_request_at = time.monotonic()
    asyncio.create_task(stop_after_abandoned_challenge())


def ensure_service_token() -> None:
    try:
        with TOKEN_PATH.open("x", encoding="ascii") as token_file:
            token_file.write(secrets.token_urlsafe(32))
    except FileExistsError:
        pass


def main() -> None:
    ensure_service_token()
    server = uvicorn.Server(
        uvicorn.Config(
            app,
            host="127.0.0.1",
            port=8765,
            workers=1,
            timeout_graceful_shutdown=30,
        )
    )
    app.state.uvicorn_server = server
    server.run()


if __name__ == "__main__":
    main()
