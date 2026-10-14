#!/usr/bin/env bash
# Generates a throwaway local CA and a "localhost" server certificate for the GreenMail
# fake IMAP/SMTP server, so integration tests run with full certificate verification
# (trusting only this CA) instead of disabling TLS checks. Output: infra/dev/tls/ (gitignored).
set -euo pipefail
DIR="$(cd "$(dirname "$0")/.." && pwd)/dev/tls"
mkdir -p "$DIR"; cd "$DIR"
[ -f ca.crt ] && [ -f greenmail.p12 ] && { echo "dev TLS material already exists in $DIR"; exit 0; }
umask 077
openssl req -x509 -newkey rsa:3072 -nodes -days 825 -subj "/CN=MbunieMail DEV TEST CA (not trusted anywhere else)" \
  -keyout ca.key -out ca.crt -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" -keyout server.key -out server.csr 2>/dev/null
printf "subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n" > ext.cnf
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 825 -extfile ext.cnf -out server.crt 2>/dev/null
PASS="$(openssl rand -hex 16)"
openssl pkcs12 -export -in server.crt -inkey server.key -certfile ca.crt -name greenmail -passout "pass:$PASS" -out greenmail.p12
printf '%s' "$PASS" > greenmail.p12.pass
rm -f server.csr ext.cnf ca.srl
chmod 644 ca.crt greenmail.p12 greenmail.p12.pass   # read by the container user; dev-only material
echo "Created dev CA and GreenMail keystore in $DIR"
