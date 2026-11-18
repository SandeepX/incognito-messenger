# The official minio/minio image and dl.min.io binaries were discontinued for
# community use. Alpine still packages the same AGPLv3 MinIO server, so we
# build an equivalent image locally with both the server and the `mc` client.
FROM alpine:latest

RUN apk add --no-cache minio minio-client ca-certificates

RUN mkdir -p /data && chown -R nobody:nobody /data

USER nobody

EXPOSE 9000 9001

ENTRYPOINT ["minio", "server", "/data", "--console-address", ":9001"]
