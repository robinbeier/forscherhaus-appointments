# Keep Kuma and its native modules unchanged while replacing the vulnerable
# Node 22 runtime. Both stages use Debian bookworm and Node module ABI 127.
FROM node:22.23.4-bookworm-slim@sha256:de170b39439eb0e5a3444ad7d60b015614a381e10fecae2e30ec9e735122f38d AS node_security
FROM louislam/uptime-kuma:2.5.6-slim@sha256:5db97dbbef610276e1699f3ece586c3509c8fd35de75d45ff5109761a1b377dc

COPY --from=node_security /usr/local/bin/node /usr/local/bin/node
ENV NODE_VERSION=22.23.4
RUN test "$(node --version)" = "v22.23.4" \
    && node -e "if (process.versions.modules !== '127') process.exit(1)"
