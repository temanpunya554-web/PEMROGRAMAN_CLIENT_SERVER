import base64
import binascii
import logging
import os
import secrets
import statistics
from pathlib import Path
from typing import Literal

os.environ.setdefault("DEEPFACE_BACKEND_ENGINE", "onnx")

import cv2
import numpy as np
from deepface import DeepFace
from deepface.modules.exceptions import FaceNotDetected, SpoofDetected
from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field


LOGGER = logging.getLogger("siabak.face")
TOKEN_PATH = Path(__file__).with_name(".service-token")
MODEL_NAME = "SFace"
MODEL_VERSION = "deepface-sface-antispoof-v1"
MAX_REQUEST_BYTES = 5 * 1024 * 1024
MAX_FRAME_BYTES = 512 * 1024
MIN_MOVEMENT = 0.08

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)


class LivenessRequest(BaseModel):
    challenge: Literal["left-right", "right-left"]
    first_frames: list[str] = Field(min_length=3, max_length=3)
    second_frames: list[str] = Field(min_length=3, max_length=3)


class VerificationRequest(LivenessRequest):
    reference: list[float] = Field(min_length=1, max_length=2048)


@app.on_event("startup")
def load_face_models() -> None:
    DeepFace.build_model(task="facial_recognition", model_name=MODEL_NAME)
    DeepFace.build_model(task="spoofing", model_name="Fasnet")


@app.middleware("http")
async def limit_request_size(request: Request, call_next):
    content_length = request.headers.get("content-length")
    if content_length:
        try:
            if int(content_length) > MAX_REQUEST_BYTES:
                return JSONResponse(status_code=413, content={"detail": "Ukuran data terlalu besar."})
        except ValueError:
            return JSONResponse(status_code=400, content={"detail": "Ukuran data tidak valid."})
    return await call_next(request)


def require_local_php(authorization: str | None) -> None:
    try:
        expected_token = TOKEN_PATH.read_text(encoding="ascii").strip()
    except OSError as error:
        LOGGER.error("Face service token file is unavailable: %s", error)
        raise HTTPException(status_code=503, detail="Token layanan verifikasi belum tersedia.") from error

    if not expected_token:
        raise HTTPException(status_code=503, detail="Token layanan verifikasi belum tersedia.")

    expected_header = f"Bearer {expected_token}"
    if not authorization or not secrets.compare_digest(authorization, expected_header):
        raise HTTPException(status_code=401, detail="Layanan hanya menerima permintaan dari aplikasi.")


def decode_frame(encoded: str) -> np.ndarray:
    try:
        raw = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError) as error:
        raise HTTPException(status_code=400, detail="Data gambar kamera tidak valid.") from error

    if not raw or len(raw) > MAX_FRAME_BYTES:
        raise HTTPException(status_code=413, detail="Ukuran bingkai kamera tidak valid.")

    image = cv2.imdecode(np.frombuffer(raw, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None or min(image.shape[:2]) < 320 or max(image.shape[:2]) > 1920:
        raise HTTPException(status_code=400, detail="Bingkai kamera harus berukuran 320–1920 piksel.")
    return image


def represent_live_frame(image: np.ndarray) -> tuple[np.ndarray, float]:
    try:
        faces = DeepFace.represent(
            img_path=image,
            model_name=MODEL_NAME,
            detector_backend="opencv",
            enforce_detection=True,
            align=True,
            anti_spoofing=True,
            max_faces=2,
        )
    except SpoofDetected as error:
        raise HTTPException(
            status_code=422,
            detail="Pemeriksaan anti-spoof menolak bingkai. Gunakan wajah langsung di depan kamera.",
        ) from error
    except FaceNotDetected as error:
        raise HTTPException(status_code=422, detail="Wajah tidak terdeteksi jelas di kamera.") from error

    if len(faces) != 1:
        raise HTTPException(status_code=422, detail="Pastikan hanya satu wajah terlihat.")

    embedding = np.asarray(faces[0]["embedding"], dtype=np.float32)
    if embedding.ndim != 1 or embedding.size == 0 or not np.isfinite(embedding).all():
        raise HTTPException(status_code=422, detail="Ciri wajah tidak dapat dihitung.")

    area = faces[0]["facial_area"]
    image_width = image.shape[1]
    center_x = (float(area["x"]) + float(area["w"]) / 2) / image_width
    return embedding, center_x


def process_liveness(payload: LivenessRequest) -> np.ndarray:
    first_images = [decode_frame(frame) for frame in payload.first_frames]
    second_images = [decode_frame(frame) for frame in payload.second_frames]
    all_images = first_images + second_images

    representations = [represent_live_frame(image) for image in all_images]
    embeddings = [item[0] for item in representations]
    centers = [item[1] for item in representations]

    first_center = statistics.median(centers[:3])
    second_center = statistics.median(centers[3:])
    movement = second_center - first_center
    if payload.challenge == "left-right" and movement < MIN_MOVEMENT:
        raise HTTPException(status_code=422, detail="Gerakan kiri lalu kanan belum terdeteksi.")
    if payload.challenge == "right-left" and movement > -MIN_MOVEMENT:
        raise HTTPException(status_code=422, detail="Gerakan kanan lalu kiri belum terdeteksi.")

    mean_embedding = np.mean(np.stack(embeddings), axis=0)
    norm = float(np.linalg.norm(mean_embedding))
    if norm == 0 or not np.isfinite(norm):
        raise HTTPException(status_code=422, detail="Ciri wajah tidak dapat dihitung.")
    reference = mean_embedding / norm

    for embedding in embeddings:
        result = DeepFace.verify(
            img1_path=reference.tolist(),
            img2_path=embedding.tolist(),
            model_name=MODEL_NAME,
            distance_metric="cosine",
            silent=True,
        )
        if not result["verified"]:
            raise HTTPException(status_code=422, detail="Wajah berubah selama pemeriksaan. Ulangi pendaftaran.")

    return reference


@app.get("/health")
def health():
    return {"success": True, "model": MODEL_VERSION}


@app.post("/v1/enroll")
def enroll(payload: LivenessRequest, authorization: str | None = Header(default=None)):
    require_local_php(authorization)
    embedding = process_liveness(payload)
    return {
        "success": True,
        "model": MODEL_VERSION,
        "embedding": [float(value) for value in embedding],
    }


@app.post("/v1/verify")
def verify(payload: VerificationRequest, authorization: str | None = Header(default=None)):
    require_local_php(authorization)
    embedding = process_liveness(payload)
    reference = np.asarray(payload.reference, dtype=np.float32)
    if (
        reference.ndim != 1
        or reference.shape != embedding.shape
        or not np.isfinite(reference).all()
        or float(np.linalg.norm(reference)) == 0
    ):
        raise HTTPException(status_code=409, detail="Pendaftaran wajah perlu diulang.")

    result = DeepFace.verify(
        img1_path=reference.tolist(),
        img2_path=embedding.tolist(),
        model_name=MODEL_NAME,
        distance_metric="cosine",
        silent=True,
    )
    if not result["verified"]:
        raise HTTPException(status_code=403, detail="Wajah tidak cocok dengan pendaftaran akun ini.")
    return {"success": True, "model": MODEL_VERSION}
