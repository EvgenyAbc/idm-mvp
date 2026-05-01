FROM node:20-alpine AS build

WORKDIR /app

COPY dashboard/package*.json ./
RUN npm ci

COPY dashboard ./

ARG VITE_API_BASE=/api
ENV VITE_API_BASE=${VITE_API_BASE}

RUN npm run build

FROM nginx:1.27-alpine

COPY dashboard/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
