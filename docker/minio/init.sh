#!/bin/sh
set -euo pipefail

MC=mcli
export HOME=/tmp

echo "Waiting for MinIO at ${MINIO_ENDPOINT}..."
for _ in $(seq 1 60); do
    if ${MC} alias set local "${MINIO_ENDPOINT}" "${MINIO_ACCESS_KEY}" "${MINIO_SECRET_KEY}" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

${MC} alias set local "${MINIO_ENDPOINT}" "${MINIO_ACCESS_KEY}" "${MINIO_SECRET_KEY}" >/dev/null

if ! ${MC} ls "local/${MINIO_BUCKET}" >/dev/null 2>&1; then
    ${MC} mb "local/${MINIO_BUCKET}"
fi

${MC} anonymous set none "local/${MINIO_BUCKET}" >/dev/null 2>&1 || true

echo "MinIO bucket '${MINIO_BUCKET}' is ready (private)."
