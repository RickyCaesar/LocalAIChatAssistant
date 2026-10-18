FROM node:20-alpine AS build

WORKDIR /app

COPY package*.json ./

RUN npm install

COPY . .

ARG VITE_API_URL
ENV VITE_API_URL=$VITE_API_URL

RUN npm run build

# Sertifikat self-signed bawaan; ditimpa oleh ./certs host bila ada (lihat
# 10-pick-certs.sh). Dibuat di stage build agar image akhir bebas openssl.
RUN apk add --no-cache openssl \
    && mkdir -p /certs-default \
    && openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
         -keyout /certs-default/key.pem -out /certs-default/cert.pem \
         -subj "/CN=myassistant" \
         -addext "subjectAltName=IP:127.0.0.1,DNS:localhost"

FROM nginx:alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY --from=build /certs-default /certs-default
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY 10-pick-certs.sh /docker-entrypoint.d/10-pick-certs.sh
RUN chmod +x /docker-entrypoint.d/10-pick-certs.sh

EXPOSE 80 443

CMD ["nginx", "-g", "daemon off;"]