FROM ghcr.io/puppeteer/puppeteer:22

WORKDIR /app

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

EXPOSE 3000

CMD ["node", "server.js"]
