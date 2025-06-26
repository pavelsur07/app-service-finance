FROM nginx:alpine

# Установим sh и envsubst
RUN apk add --no-cache bash

COPY nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY site/public /usr/share/nginx/html

# Стартовый скрипт, который подставляет переменные и запускает nginx
CMD ["/bin/sh", "-c", "envsubst < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf && nginx -g 'daemon off;'"]
