FROM php:8.4-cli AS build
WORKDIR /app
COPY pages/ pages/
COPY build.php .
RUN php build.php

FROM nginx:alpine
COPY --from=build /app/html/ /usr/share/nginx/html/
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 8080
