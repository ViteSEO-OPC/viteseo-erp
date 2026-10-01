#!/usr/bin/env bash
# Pre-deploy database backup, verification and restore for the staging -> production release.
#
#   db-backup-restore.sh backup   <outdir>                 dump the live db (read-only) + manifest + snapshot
#   db-backup-restore.sh verify   <outdir> <scratch_db>    restore the dump into a THROWAWAY db and compare
#                                                          every table (rows AND checksums) with the live
#                                                          snapshot taken right after the dump; exit 1 on any diff
#   db-backup-restore.sh restore  <outdir> <target_db>     replace <target_db> with the dump (THE ROLLBACK).
#                                                          Needs CONFIRM_RESTORE=<target_db> whenever the
#                                                          target already exists.
#   db-backup-restore.sh drop-scratch <scratch_db>         drop a *_rehearsal / *_verify db (nothing else)
#   db-backup-restore.sh snapshot <db> <file>              per-table "name rows checksum" list
#   db-backup-restore.sh compare  <fileA> <fileB>          diff two snapshots (exit 1 if different)
#
# Run as root on the host (MariaDB root over the unix socket). Every artifact is 0600 in a 0700 dir: the dump
# holds password hashes, token hashes and encrypted provider credentials.
#
# SOURCE DB: `teamora` unless SOURCE_DB is set (only `backup` reads it; it is validated like any name).
# The dump is stored as db.sql.gz and the MANIFEST records which db it came from and its charset/collation,
# which `verify`/`restore` reuse. No command ever drops a database without a guard:
#  - verify / drop-scratch: only names ending in _rehearsal or _verify;
#  - restore: CONFIRM_RESTORE must equal the target name if the target exists (any db, live or not);
#    the dump file and its checksum are validated BEFORE anything is dropped.
set -euo pipefail
umask 077

SOURCE_DB=${SOURCE_DB:-teamora}
MYSQL=(mysql --default-character-set=utf8mb4)
DUMP=(mysqldump --default-character-set=utf8mb4 --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces)

die() { echo "STOP: $*" >&2; exit 2; }
safe_name() { [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || die "invalid database name '$1'"; }
scratch_name() { safe_name "$1"; [[ "$1" =~ _(rehearsal|verify)$ ]] || die "scratch db must end in _rehearsal or _verify (got '$1')"; }
abs_dir() { local d; d=$(cd "$1" 2>/dev/null && pwd -P) || die "no such directory: $1"; echo "$d"; }
db_exists() { [ "$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$1'")" = 1 ]; }
manifest() { grep -E "^$2=" "$1/MANIFEST" | head -1 | cut -d= -f2-; }
check_dump() { # <absdir> -> validates the dump BEFORE any destructive step
    [ -f "$1/MANIFEST" ] && [ -f "$1/db.sql.gz" ] && [ -f "$1/SHA256SUMS" ] || die "$1 is not a complete backup (MANIFEST, db.sql.gz, SHA256SUMS)"
    (cd "$1" && sha256sum -c --quiet SHA256SUMS) || die "checksum mismatch: the dump changed since backup"
    gzip -t "$1/db.sql.gz" || die "dump is not a valid gzip"
}
create_like_source() { # <target> <absdir>
    local cs col; cs=$(manifest "$2" charset); col=$(manifest "$2" collation)
    [[ "$cs" =~ ^[a-z0-9_]+$ && "$col" =~ ^[a-z0-9_]+$ ]] || die "manifest has no charset/collation"
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET $cs COLLATE $col"
}
load() { # <target> <absdir>
    local s; s=$(date +%s)
    zcat "$2/db.sql.gz" | "${MYSQL[@]}" "$1" || die "loading the dump into $1 FAILED — re-run the same command (the dump is intact)"
    echo "loaded $1 in $(( $(date +%s) - s ))s"
}

snapshot() { # <db> -> stdout: "<table> <rows> <checksum>" for every base table, sorted
    local db=$1 t rows sum
    safe_name "$db"
    for t in $("${MYSQL[@]}" -N -B -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$db' AND table_type='BASE TABLE' ORDER BY table_name"); do
        rows=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM \`$db\`.\`$t\`")
        sum=$("${MYSQL[@]}" -N -B -e "CHECKSUM TABLE \`$db\`.\`$t\` EXTENDED" | awk '{print $2}')
        echo "$t $rows $sum"
    done
}

cmd=${1:-}; shift || true
case "$cmd" in
backup)
    out=${1:?outdir}; safe_name "$SOURCE_DB"
    [ -e "$out" ] && die "$out already exists — never overwrite a backup"
    mkdir -p "$out"; chmod 700 "$out"; out=$(abs_dir "$out")
    db_exists "$SOURCE_DB" || die "no database $SOURCE_DB"
    other=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SOURCE_DB' AND table_type='BASE TABLE' AND engine<>'InnoDB'")
    [ "$other" = 0 ] || die "$other non-InnoDB table(s): --single-transaction would not give a consistent dump"
    read -r cs col < <("${MYSQL[@]}" -N -B -e "SELECT default_character_set_name, default_collation_name FROM information_schema.schemata WHERE schema_name='$SOURCE_DB'")
    [[ "$cs" =~ ^[a-z0-9_]+$ && "$col" =~ ^[a-z0-9_]+$ ]] || die "could not read the charset/collation of $SOURCE_DB"
    start=$(date -u +%FT%TZ)
    if ! "${DUMP[@]}" "$SOURCE_DB" 2>"$out/dump.err" | gzip -9 > "$out/db.sql.gz"; then
        cat "$out/dump.err" >&2; die "mysqldump failed — $out is incomplete; remove it and retry"
    fi
    [ -s "$out/dump.err" ] && { cat "$out/dump.err" >&2; die "mysqldump wrote to stderr"; }
    gzip -t "$out/db.sql.gz" || die "dump is not a valid gzip"
    zcat "$out/db.sql.gz" | tail -n 1 | grep -q '^-- Dump completed' || die "dump is truncated (no 'Dump completed' trailer)"
    tables=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SOURCE_DB' AND table_type='BASE TABLE'")
    dumped=$(zcat "$out/db.sql.gz" | grep -c '^CREATE TABLE ')
    [ "$tables" = "$dumped" ] || die "live has $tables tables, dump has $dumped"
    migrations=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*), MAX(batch) FROM \`$SOURCE_DB\`.migrations" | tr '\t' '/')
    (cd "$out" && sha256sum db.sql.gz > SHA256SUMS)
    {
        echo "source_db=$SOURCE_DB"; echo "charset=$cs"; echo "collation=$col"
        echo "started=$start"; echo "finished=$(date -u +%FT%TZ)"
        echo "tables=$tables"; echo "migrations(count/max_batch)=$migrations"
        echo "bytes=$(stat -c %s "$out/db.sql.gz")"; echo "sha256=$(cut -c1-64 "$out/SHA256SUMS")"
    } > "$out/MANIFEST"
    # Exact only while writes are stopped (maintenance mode + queue/scheduler stopped): the release runbook
    # stops them before this step, so `verify` can demand identity.
    snapshot "$SOURCE_DB" > "$out/source-after-dump.snapshot"
    chmod 600 "$out"/*
    cat "$out/MANIFEST"
    ;;
verify)
    out=$(abs_dir "${1:?outdir}"); scratch=${2:?scratch db}
    scratch_name "$scratch"; check_dump "$out"
    create_like_source "$scratch" "$out"
    load "$scratch" "$out" | tee -a "$out/MANIFEST"
    snapshot "$scratch" > "$out/$scratch.snapshot"; chmod 600 "$out"/*
    if diff "$out/source-after-dump.snapshot" "$out/$scratch.snapshot" > "$out/verify.diff"; then
        echo "VERIFY OK: all $(wc -l < "$out/$scratch.snapshot") tables identical to the source at dump time (rows + checksums)"
    else
        echo "VERIFY FAILED: differences below (with writes stopped there must be none):"; cat "$out/verify.diff"; exit 1
    fi
    ;;
restore)
    out=$(abs_dir "${1:?outdir}"); target=${2:?target db}
    safe_name "$target"; check_dump "$out"
    if db_exists "$target" && [ "${CONFIRM_RESTORE:-}" != "$target" ]; then
        die "$target exists and would be DROPPED and replaced from $(manifest "$out" source_db)@$(manifest "$out" finished). Set CONFIRM_RESTORE=$target to proceed (see the rollback runbook)."
    fi
    create_like_source "$target" "$out"
    load "$target" "$out"
    ;;
drop-scratch)
    scratch=${1:?scratch db}; scratch_name "$scratch"
    "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$scratch\`"; echo "dropped $scratch"
    ;;
snapshot)
    snapshot "${1:?db}" > "${2:?file}"; chmod 600 "$2"
    ;;
compare)
    if diff "${1:?a}" "${2:?b}"; then echo "IDENTICAL"; else echo "DIFFERENT"; exit 1; fi
    ;;
*)
    sed -n '2,24p' "$0"; exit 64
    ;;
esac
