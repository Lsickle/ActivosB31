# Respaldo diario de la base de datos (Neon) a Cloudflare R2. Se despliega
# como Cron Job de Render (docs/despliegue.md): Root Directory = ops/backup.
#
# pg_dump 17.11 (cliente de la misma versión mayor que Neon, PG 17) + rclone
# + age (cifrado opcional del dump, B3) + ca-certificates (sslrootcert=system
# para sslmode=verify-full, B6).
# El bucket y el token son OTROS que los de las fotos: la app no puede leer ni
# borrar respaldos (docs/infraestructura.md).

FROM rclone/rclone:1.75.2 AS rclone

FROM postgres:17.11-alpine3.24

# age y ca-certificates de los repositorios de Alpine 3.24 (main/community).
RUN apk add --no-cache age ca-certificates \
    && age --version

# Binario estático de rclone (no depende de libc).
COPY --from=rclone /usr/local/bin/rclone /usr/local/bin/rclone
COPY backup.sh /usr/local/bin/backup.sh
RUN chmod 0755 /usr/local/bin/backup.sh

# No hace falta el servidor de PostgreSQL ni su entrypoint: solo los clientes.
USER postgres
WORKDIR /tmp

ENTRYPOINT []
CMD ["/usr/local/bin/backup.sh"]
