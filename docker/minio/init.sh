#!/bin/sh
# One-shot bucket provisioning. Runs after MinIO reports healthy.
set -euo pipefail

# Alpine's minio-client package installs the binary as `mcli`.
# The container runs as nobody, so point its config at a writable directory.
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

# Private by default: remove any anonymous policy that might have been set.
${MC} anonymous set none "local/${MINIO_BUCKET}" >/dev/null 2>&1 || true

echo "MinIO bucket '${MINIO_BUCKET}' is ready (private)."
