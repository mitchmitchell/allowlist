# Allowlist smoke test

`tests/smoke/allowlist-smoke.sh` checks the module on a **dedicated FreePBX 17 / Asterisk 21 test PBX**.
Every agent and checker runs the same command. Host and credentials come from environment variables only.

```bash
export ALLOWLIST_TEST_HOST=<tailscale name or IP>
export ALLOWLIST_TEST_I_AM_A_TEST_PBX=yes        # required guard
export ALLOWLIST_TEST_SSH_USER=root               # or a sudo user + ALLOWLIST_TEST_SUDO="sudo -n"
export ALLOWLIST_TEST_API_CLIENT_ID=...           # FreePBX API module, machine-to-machine app, gql scope
export ALLOWLIST_TEST_API_CLIENT_SECRET=...       # (gql stage is skipped if unset)
export ALLOWLIST_TEST_INSECURE_TLS=1              # if the PBX uses a self-signed cert
tests/smoke/allowlist-smoke.sh                    # all stages
tests/smoke/allowlist-smoke.sh i18n               # local only, no PBX needed
```

The PBX must also carry a marker file (`/etc/allowlist-test-pbx` by default); the script refuses to touch a host without it.

| Stage | What it checks |
|---|---|
| preflight | SSH, marker file, FreePBX/Asterisk versions, pause state |
| install | Uploads this checkout, `fwconsole ma install/enable allowlist`, reload, version + Enabled |
| dialplan | Module contexts and AGI scripts exist; reports Macro() use on Asterisk 21 |
| cli | `fwconsole allowlist --import/--list/--delete`; settings keys hidden from `--list` |
| gql | OAuth token; `allowlistSettings`, `addAllowlist`, `allAllowlists`, `allowlist(id)`, `removeAllowlist`; lists the schema's allowlist fields |
| behavior | Temporary in-memory dialplan + Local channel (no trunk): listed caller allowed, unlisted blocked, removed blocked |
| i18n | `.pot` current with `_()` strings in PHP/JS, each `.po` compiles and covers the `.pot`, `.mo` matches `.po` |

Known bugs from the 17.0.1.1 review are probed and reported as **XFAIL** (finding 1 `dest`/`knowncallers` caller ID, finding 10 unknown GraphQL id, Macro() on Asterisk 21). They do not fail the run unless `ALLOWLIST_TEST_STRICT=1`; a fix PR should flip its XFAIL to PASS and the checker runs with `STRICT=1` for that finding.

Test numbers are in the fictional 1555-0100xx range and are removed on exit, along with the temporary dialplan context `allowlist-smoke-run` and AstDB family `allowlistsmoke`. The script never changes the blocked-call destination, pause or known-callers settings.

Exit codes: 0 pass, 1 a check failed, 2 configuration error, 3 safety guard refused.

Status (Oct 18, 2026): lint-clean (shellcheck), i18n stage run locally (pass), remote stages exercised only against a mock; not yet run on a real PBX. Phase 0 validates it.
