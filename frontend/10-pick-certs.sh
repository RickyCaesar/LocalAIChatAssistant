#!/bin/sh
# Sertifikat TLS: pakai ./certs dari host (mount /certs-host, hasil openssl
# sesuai README "Akses dari HP") bila ada; kalau tidak, self-signed bawaan.
set -e
mkdir -p /etc/nginx/certs
if [ -s /certs-host/cert.pem ] && [ -s /certs-host/key.pem ]; then
    cp /certs-host/cert.pem /certs-host/key.pem /etc/nginx/certs/
else
    cp /certs-default/cert.pem /certs-default/key.pem /etc/nginx/certs/
fi
