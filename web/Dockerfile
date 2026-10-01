# Nginx TLS terminator + static frontend for the Document Embedding Manager.
FROM nginx:1.27-alpine

RUN apk add --no-cache openssl curl

COPY nginx.conf /etc/nginx/nginx.conf
COPY security-headers.conf /etc/nginx/security-headers.conf
COPY docker-entrypoint.sh /docker-entrypoint.sh
# Strip CR in case the repository was checked out with CRLF line endings.
RUN sed -i 's/\r$//' /docker-entrypoint.sh && chmod +x /docker-entrypoint.sh

COPY html /usr/share/nginx/html

EXPOSE 443

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
