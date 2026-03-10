FROM ghcr.io/puppeteer/puppeteer:24.38.0

USER root

WORKDIR /app

RUN mkdir -p /app && chown -R pptruser:pptruser /app

RUN apt-get update || true
RUN apt-get install -y --no-install-recommends \
        fontconfig \
        fonts-dejavu \
        fonts-liberation \
        fonts-noto-core \
        fonts-noto-color-emoji \
    || true

COPY package.json package-lock.json* ./
RUN npm ci --omit=dev || npm install --omit=dev

COPY server.js ./

RUN chown -R pptruser:pptruser /app

USER pptruser

EXPOSE 3000

CMD ["node", "server.js"]
