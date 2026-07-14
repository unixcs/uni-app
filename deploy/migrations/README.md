# Incremental production migrations

Put only forward-compatible, idempotent `NNNN_description.sql` migrations here.
The server release command verifies each checksum and records it in
`yoshop_deploy_migrations`. Initial schema/data import is intentionally handled
by the first-production cutover task, not by normal code releases.
