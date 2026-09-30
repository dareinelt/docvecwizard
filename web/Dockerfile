# Nginx TLS terminator + static frontend for the Document Embedding Manager.
FROM nginx:1.27-alpine

RUN apk add --no-cache openssl curl

COPY nginx.conf /etc/nginx/nginx.conf
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

COPY html /usr/share/nginx/html

EXPOSE 443

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
