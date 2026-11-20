FROM alpine:latest

RUN apk add --no-cache minio minio-client ca-certificates

RUN mkdir -p /data && chown -R nobody:nobody /data

USER nobody

EXPOSE 9000 9001

ENTRYPOINT ["minio", "server", "/data", "--console-address", ":9001"]
