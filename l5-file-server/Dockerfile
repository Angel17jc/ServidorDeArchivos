FROM node:22-alpine

# curl e iputils para las pruebas de conectividad (Evidencias 2, 3 y 4) desde dentro del contenedor
RUN apk add --no-cache curl iputils

WORKDIR /app
COPY package*.json ./
RUN npm install --omit=dev && npm cache clean --force
COPY src ./src

ENV NODE_ENV=production \
    PORT=8080 \
    STORAGE_DIR=/srv/storage

RUN mkdir -p /srv/storage && chown -R node:node /srv/storage
USER node

EXPOSE 8080
VOLUME ["/srv/storage"]

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD curl -fs http://127.0.0.1:8080/health || exit 1

CMD ["node", "src/server.js"]
