#!/bin/sh
# A streaming replica of the local primary, for reports (ADR 0053).
#
# First start: copy the primary with pg_basebackup. `-R` writes the
# standby.signal and primary_conninfo that make this a hot standby. Every later
# start: the data directory is already a standby, so postgres simply resumes
# streaming.
#
# No replication slot, on purpose. A slot keeps WAL on the primary for a
# replica that may be stopped for weeks on a laptop, and fills the disk; in
# development, a replica that fell too far behind is rebuilt with
# `docker compose --profile replica down -v` and started again.
set -eu

if [ ! -s "$PGDATA/PG_VERSION" ]; then
  until pg_isready -h postgres -U workos -d workos >/dev/null 2>&1; do
    echo "replica: waiting for the primary"
    sleep 1
  done

  # The container runs as `postgres` (docker-compose `user:`), and a fresh
  # named volume takes the image's ownership of this directory — so no chown,
  # and no su-exec/gosu, which the alpine image does not reliably ship.
  mkdir -p "$PGDATA"
  chmod 700 "$PGDATA"

  PGPASSWORD=workos pg_basebackup -h postgres -U workos -D "$PGDATA" -R -X stream --no-password

  echo "replica: base backup taken; starting as a hot standby"
fi

exec postgres -c hot_standby=on
