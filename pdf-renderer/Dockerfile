FROM node:24-bookworm-slim

ENV PUPPETEER_CACHE_DIR=/home/node/.cache/puppeteer

WORKDIR /app

COPY --chown=node:node package.json package-lock.json* ./

RUN PUPPETEER_SKIP_DOWNLOAD=true npm ci --omit=dev \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        fontconfig \
        fonts-dejavu \
        fonts-liberation \
        fonts-noto-core \
        fonts-noto-color-emoji \
    && npx puppeteer browsers install chrome --install-deps \
    && mkdir -p "$PUPPETEER_CACHE_DIR" \
    && chown -R node:node "$PUPPETEER_CACHE_DIR" \
    && npm cache clean --force \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=node:node server.js server.test.js ./

USER node

EXPOSE 3000

CMD ["node", "server.js"]
