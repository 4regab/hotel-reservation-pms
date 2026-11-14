# nginx in front of the four PHP-FPM services. It serves the portal's static files itself.
FROM nginx:1.27-alpine
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY portal/public /app/portal/public
