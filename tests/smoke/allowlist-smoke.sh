#!/usr/bin/env bash
# Allowlist module smoke test for a dedicated FreePBX 17 / Asterisk 21 TEST PBX.
#
# Runs from a workstation or agent box. Talks to the PBX over SSH (fwconsole,
# asterisk -rx) and HTTPS (GraphQL). Host and credentials come ONLY from the
# environment; nothing secret is written to disk or put on a command line.
#
# Usage:  tests/smoke/allowlist-smoke.sh [stage ...]
# Stages: preflight install dialplan cli gql behavior i18n   (default: all, in that order)
#         i18n is local-only and needs no PBX.
#
# Required env (PBX stages):
#   ALLOWLIST_TEST_HOST            Tailscale name or IP of the TEST PBX
#   ALLOWLIST_TEST_I_AM_A_TEST_PBX must be "yes" (guard against pointing at production)
# Optional env:
#   ALLOWLIST_TEST_SSH_USER        default root
#   ALLOWLIST_TEST_SSH_PORT        default 22
#   ALLOWLIST_TEST_SSH_KEY         path to a private key; default: ssh-agent / ssh config
#   ALLOWLIST_TEST_SUDO            "sudo -n" when SSH_USER is not root (default empty)
#   ALLOWLIST_TEST_MARKER          marker file that must exist on the PBX
#                                  (default /etc/allowlist-test-pbx)
#   ALLOWLIST_TEST_API_URL         default https://$ALLOWLIST_TEST_HOST/admin/api/api
#   ALLOWLIST_TEST_API_CLIENT_ID   OAuth machine-to-machine client id   } gql stage is
#   ALLOWLIST_TEST_API_CLIENT_SECRET OAuth client secret                } skipped if unset
#   ALLOWLIST_TEST_API_SCOPE       default "gql"
#   ALLOWLIST_TEST_INSECURE_TLS    "1" to accept a self-signed certificate
#   ALLOWLIST_TEST_MODULE_SRC      module source dir to install (default: this checkout)
#   ALLOWLIST_TEST_SKIP_INSTALL    "1" to test the module already on the PBX
#   ALLOWLIST_TEST_STRICT          "1" to make known-bug probes (XFAIL) fail the run
#   ALLOWLIST_TEST_DRY_RUN         "1" to print remote commands instead of running them
#
# Exit codes: 0 all pass, 1 a check failed, 2 configuration error, 3 safety guard refused.

# shellcheck disable=SC2015,SC2317  # pass/fail always return 0; stages are called as st_$name
set -uo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_DIR=$(cd "$SCRIPT_DIR/../.." && pwd)
MODULE_SRC=${ALLOWLIST_TEST_MODULE_SRC:-$REPO_DIR}
HOST=${ALLOWLIST_TEST_HOST:-}
SSH_USER=${ALLOWLIST_TEST_SSH_USER:-root}
SSH_PORT=${ALLOWLIST_TEST_SSH_PORT:-22}
SUDO=${ALLOWLIST_TEST_SUDO:-}
MARKER=${ALLOWLIST_TEST_MARKER:-/etc/allowlist-test-pbx}
API_URL=${ALLOWLIST_TEST_API_URL:-${HOST:+https://$HOST/admin/api/api}}
API_SCOPE=${ALLOWLIST_TEST_API_SCOPE:-gql}
DRY=${ALLOWLIST_TEST_DRY_RUN:-0}
STRICT=${ALLOWLIST_TEST_STRICT:-0}

PASS=0 FAIL=0 XFAIL=0 SKIP=0
RUN_ID=$(( RANDOM % 90 + 10 ))
NUM_A="155501000${RUN_ID}"      # fictional 555-01xx range
NUM_B="155501001${RUN_ID}"
NUM_GQL="155501002${RUN_ID}"
NUM_ABSENT="155501009${RUN_ID}"
SMOKE_CTX="allowlist-smoke-run"
SMOKE_FAMILY="allowlistsmoke"
CLEANUP_NUMBERS=()
CLEANUP_CTX=0
TMPD=$(mktemp -d)

pass()  { PASS=$((PASS+1));  printf '  PASS  %s\n' "$*"; }
fail()  { FAIL=$((FAIL+1));  printf '  FAIL  %s\n' "$*"; }
xfail() { XFAIL=$((XFAIL+1)); printf '  XFAIL %s (known bug)\n' "$*"; }
skip()  { SKIP=$((SKIP+1));  printf '  SKIP  %s\n' "$*"; }
info()  { printf '  ..    %s\n' "$*"; }
die()   { printf 'ERROR: %s\n' "$2" >&2; exit "$1"; }
stage() { printf '\n== %s\n' "$*"; }

# ---------- remote helpers ----------
ssh_opts=(-o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -p "$SSH_PORT")
[[ -n ${ALLOWLIST_TEST_SSH_KEY:-} ]] && ssh_opts+=(-i "$ALLOWLIST_TEST_SSH_KEY" -o IdentitiesOnly=yes)

# remote CMD...  -> runs CMD on the PBX (with $SUDO), stdout returned
remote() {
	if [[ $DRY == 1 ]]; then printf '[dry-run] ssh %s@%s %s %s\n' "$SSH_USER" "$HOST" "$SUDO" "$*" >&2; return 0; fi
	# shellcheck disable=SC2029  # intentional client-side expansion
	ssh "${ssh_opts[@]}" "$SSH_USER@$HOST" "$SUDO $*"
}
ast() { remote asterisk -rx "$(printf '%q' "$1")"; }
astdb_get() { ast "database get $1 $2" | sed -n 's/^Value: //p'; }

need_pbx() {
	[[ -n $HOST ]] || die 2 "ALLOWLIST_TEST_HOST is not set"
	[[ ${ALLOWLIST_TEST_I_AM_A_TEST_PBX:-} == yes ]] || die 3 "set ALLOWLIST_TEST_I_AM_A_TEST_PBX=yes to confirm $HOST is a TEST PBX"
}

cleanup() {
	[[ $DRY == 1 || -z $HOST ]] && { rm -rf "$TMPD"; return; }
	local n
	for n in "${CLEANUP_NUMBERS[@]}"; do ast "database del allowlist $n" >/dev/null 2>&1; done
	if [[ $CLEANUP_CTX == 1 ]]; then
		ast "database deltree $SMOKE_FAMILY" >/dev/null 2>&1
		for n in _X. dest knowncallers; do ast "dialplan remove extension $n@$SMOKE_CTX" >/dev/null 2>&1; done
	fi
	rm -rf "$TMPD"
}
trap cleanup EXIT

# ---------- stages ----------
st_preflight() {
	stage "preflight"
	need_pbx
	for t in ssh curl jq; do command -v "$t" >/dev/null || die 2 "missing local tool: $t"; done
	if [[ $DRY == 1 ]]; then skip "remote preflight (dry run)"; return; fi
	remote true >/dev/null 2>&1 || { fail "ssh $SSH_USER@$HOST:$SSH_PORT"; die 1 "cannot reach PBX over SSH"; }
	pass "ssh reachable"
	remote test -e "$MARKER" || die 3 "marker $MARKER missing on $HOST; refusing to touch a PBX that is not marked as TEST"
	pass "test marker $MARKER present"
	local fw ast_v
	fw=$(remote fwconsole -V 2>/dev/null | head -1)
	ast_v=$(ast "core show version" | head -1)
	info "FreePBX: ${fw:-unknown}"; info "Asterisk: ${ast_v:-unknown}"
	[[ $ast_v == *"Asterisk 21"* ]] && pass "Asterisk 21" || info "not Asterisk 21 (macro findings may not reproduce)"
	[[ $(astdb_get allowlist pause) == "" ]] && pass "allowlist not paused" || info "allowlist is PAUSED; behavior stage will skip"
}

st_install() {
	stage "install / enable"
	need_pbx
	if [[ ${ALLOWLIST_TEST_SKIP_INSTALL:-0} == 1 ]]; then skip "install (ALLOWLIST_TEST_SKIP_INSTALL=1)"; else
		[[ -f $MODULE_SRC/module.xml ]] || die 2 "no module.xml in $MODULE_SRC"
		tar -C "$MODULE_SRC" --exclude=.git --exclude=tests --exclude=node_modules -czf "$TMPD/allowlist.tgz" .
		if [[ $DRY == 1 ]]; then info "[dry-run] would upload $(du -h "$TMPD/allowlist.tgz" | cut -f1) and install"; else
			# shellcheck disable=SC2029
			ssh "${ssh_opts[@]}" "$SSH_USER@$HOST" "$SUDO rm -rf /tmp/allowlist-smoke-src && $SUDO mkdir -p /tmp/allowlist-smoke-src && $SUDO tar -xzf - -C /tmp/allowlist-smoke-src" < "$TMPD/allowlist.tgz" \
				|| { fail "upload module"; return; }
			remote "rsync -a --delete /tmp/allowlist-smoke-src/ /var/www/html/admin/modules/allowlist/ && fwconsole chown >/dev/null" \
				&& pass "module files copied" || { fail "copy module files"; return; }
		fi
		remote fwconsole ma install allowlist >/dev/null 2>&1 && pass "fwconsole ma install allowlist" || fail "fwconsole ma install allowlist"
		remote fwconsole ma enable allowlist  >/dev/null 2>&1 && pass "fwconsole ma enable allowlist"  || fail "fwconsole ma enable allowlist"
		remote fwconsole reload >/dev/null 2>&1 && pass "fwconsole reload" || fail "fwconsole reload"
	fi
	[[ $DRY == 1 ]] && return
	local want got
	want=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$MODULE_SRC/module.xml" | head -1)
	got=$(remote fwconsole ma list 2>/dev/null | awk -F'|' '$2 ~ /^ *allowlist *$/ {gsub(/ /,"",$3); gsub(/ /,"",$4); print $3" "$4}')
	[[ $got == "$want Enabled" ]] && pass "module $want Enabled" || fail "module status: '${got:-absent}' (want '$want Enabled')"
}

st_dialplan() {
	stage "dialplan"
	need_pbx
	[[ $DRY == 1 ]] && { skip "dialplan (dry run)"; return; }
	local ctx
	for ctx in app-allowlist app-allowlist-check app-allowlist-add app-allowlist-remove app-allowlist-last app-allowlist-pause-toggle; do
		ast "dialplan show $ctx" | grep -q "'$ctx'" && pass "context $ctx" || fail "context $ctx missing"
	done
	remote test -x /var/lib/asterisk/agi-bin/allowlist-check.agi && pass "allowlist-check.agi installed" || fail "allowlist-check.agi missing"
	remote test -x /var/lib/asterisk/agi-bin/allowlist-autoadd.agi && pass "allowlist-autoadd.agi installed" || fail "allowlist-autoadd.agi missing"
	# Review "live check" item: Macro() is gone in Asterisk 21.
	if ast "core show application Macro" | grep -q "not registered\|No such"; then
		if ast "dialplan show app-allowlist-add" | grep -q "Macro("; then
			xfail "dialplan calls Macro() but Asterisk has no Macro app (review live-check: macros)"
		else pass "no Macro() calls"; fi
	else info "Macro app is registered on this Asterisk"; fi
	ast "dialplan show macro-dialout-trunk" | grep -q "allowlist-autoadd" \
		&& info "auto-add is spliced into macro-dialout-trunk" \
		|| info "auto-add not in macro-dialout-trunk (no auto-add route, or finding 6 / macro context missing)"
}

st_cli() {
	stage "fwconsole allowlist"
	need_pbx
	[[ $DRY == 1 ]] && { skip "cli (dry run)"; return; }
	printf '%s,%s\n' "$NUM_A" "smoke A" "$NUM_B" "smoke B" > "$TMPD/import.csv"
	# shellcheck disable=SC2029
	ssh "${ssh_opts[@]}" "$SSH_USER@$HOST" "cat > /tmp/allowlist-smoke-import.csv" < "$TMPD/import.csv"
	CLEANUP_NUMBERS+=("$NUM_A" "$NUM_B")
	remote fwconsole allowlist --import /tmp/allowlist-smoke-import.csv >/dev/null 2>&1; remote rm -f /tmp/allowlist-smoke-import.csv
	[[ $(astdb_get allowlist "$NUM_A") == "smoke A" ]] && pass "--import adds $NUM_A" || fail "--import did not add $NUM_A"
	remote fwconsole allowlist --list 2>/dev/null | grep -q "$NUM_B" && pass "--list shows $NUM_B" || fail "--list missing $NUM_B"
	remote fwconsole allowlist --delete "$NUM_B" >/dev/null 2>&1
	[[ -z $(astdb_get allowlist "$NUM_B") ]] && pass "--delete removes $NUM_B" || fail "--delete left $NUM_B"
	remote fwconsole allowlist --list 2>/dev/null | grep -qE '^\| *(dest|pause|knowncallers|did/|autoadd/)' \
		&& fail "--list shows internal settings keys" || pass "--list hides settings keys"
}

gql() { # gql QUERY_JSON -> response body
	local body=$1
	curl -sS ${ALLOWLIST_TEST_INSECURE_TLS:+-k} -H @"$TMPD/auth.hdr" -H 'Content-Type: application/json' \
		--data-binary @- "$API_URL/gql" <<<"$body"
}
gq() { jq -cn --arg q "$1" '{query:$q}'; }

st_gql() {
	stage "GraphQL API"
	need_pbx
	if [[ -z ${ALLOWLIST_TEST_API_CLIENT_ID:-} || -z ${ALLOWLIST_TEST_API_CLIENT_SECRET:-} ]]; then skip "gql (API client id/secret not set)"; return; fi
	[[ $DRY == 1 ]] && { skip "gql (dry run)"; return; }
	local tok
	# Secret goes via stdin (curl -d @-), never argv.
	tok=$(printf 'grant_type=client_credentials&client_id=%s&client_secret=%s&scope=%s' \
			"$(jq -rn --arg v "$ALLOWLIST_TEST_API_CLIENT_ID" '$v|@uri')" \
			"$(jq -rn --arg v "$ALLOWLIST_TEST_API_CLIENT_SECRET" '$v|@uri')" \
			"$(jq -rn --arg v "$API_SCOPE" '$v|@uri')" \
		| curl -sS ${ALLOWLIST_TEST_INSECURE_TLS:+-k} -d @- "$API_URL/token" | jq -r '.access_token // empty')
	[[ -n $tok ]] || { fail "OAuth token from $API_URL/token"; return; }
	( umask 077; printf 'Authorization: Bearer %s\n' "$tok" > "$TMPD/auth.hdr" )
	pass "OAuth token"

	local r gid
	r=$(gql "$(gq '{ allowlistSettings { pauseAllowlist } }')")
	jq -e '.data.allowlistSettings | has("pauseAllowlist")' <<<"$r" >/dev/null && pass "query allowlistSettings" || fail "allowlistSettings: $r"

	CLEANUP_NUMBERS+=("$NUM_GQL")
	r=$(gql "$(gq "mutation { addAllowlist(input:{number:\"$NUM_GQL\", description:\"smoke gql\", clientMutationId:\"smoke\"}) { allowlist { id number description } } }")")
	[[ $(jq -r '.data.addAllowlist.allowlist.number // empty' <<<"$r") == "$NUM_GQL" ]] && pass "mutation addAllowlist" || fail "addAllowlist: $r"
	gid=$(jq -r '.data.addAllowlist.allowlist.id // empty' <<<"$r")

	r=$(gql "$(gq '{ allAllowlists { totalCount allowlists { number description } } }')")
	jq -e --arg n "$NUM_GQL" '.data.allAllowlists.allowlists | map(.number) | index($n)' <<<"$r" >/dev/null \
		&& pass "query allAllowlists includes $NUM_GQL" || fail "allAllowlists: $r"
	jq -e '.data.allAllowlists.allowlists | map(.number) | any(. == "dest" or . == "pause" or . == "knowncallers" or startswith("did/") or startswith("autoadd/")) | not' <<<"$r" >/dev/null \
		&& pass "allAllowlists hides settings keys" || fail "allAllowlists exposes settings keys"

	if [[ -n $gid ]]; then
		r=$(gql "$(gq "{ allowlist(id:\"$gid\") { number } }")")
		[[ $(jq -r '.data.allowlist.number // empty' <<<"$r") == "$NUM_GQL" ]] && pass "query allowlist(id)" || fail "allowlist(id): $r"
	fi
	# Finding 10: unknown number must return null, not the first entry.
	gid=$(printf 'allowlist:%s' "$NUM_ABSENT" | base64)
	r=$(gql "$(gq "{ allowlist(id:\"$gid\") { number } }")")
	if [[ $(jq -r '.data.allowlist' <<<"$r") == null ]]; then pass "allowlist(id) unknown -> null (finding 10)"
	else xfail "allowlist(id) unknown returned $(jq -c '.data.allowlist' <<<"$r") (finding 10)"; fi

	r=$(gql "$(gq "mutation { removeAllowlist(input:{number:\"$NUM_GQL\", clientMutationId:\"smoke\"}) { deletedId } }")")
	[[ $(jq -r '.data.removeAllowlist.deletedId // empty' <<<"$r") == "$NUM_GQL" && -z $(astdb_get allowlist "$NUM_GQL") ]] \
		&& pass "mutation removeAllowlist" || fail "removeAllowlist: $r"
	# Report schema coverage for Phase 2 (informational).
	r=$(gql "$(gq '{ __schema { queryType { fields { name } } mutationType { fields { name } } } }')")
	info "allowlist GQL fields: $(jq -r '[.data.__schema.queryType.fields[]?.name, .data.__schema.mutationType.fields[]?.name] | map(select(test("(?i)allowlist"))) | join(", ")' <<<"$r")"
}

st_behavior() {
	stage "inbound allowlist behavior (Local channel, no trunk)"
	need_pbx
	[[ $DRY == 1 ]] && { skip "behavior (dry run)"; return; }
	[[ -n $(astdb_get allowlist pause) ]] && { skip "allowlist is paused"; return; }
	# Temporary in-memory dialplan (gone on the next reload; removed on exit).
	# No inner double quotes: the Asterisk CLI tokenizer would strip them.
	CLEANUP_CTX=1
	local e
	for e in _X. dest knowncallers; do
		ast "dialplan add extension \"$e,1,Set(CALLERID(num)=\${EXTEN})\" into $SMOKE_CTX replace" >/dev/null
		ast "dialplan add extension \"$e,2,Set(alreturnhere=1)\" into $SMOKE_CTX replace" >/dev/null
		ast "dialplan add extension \"$e,3,Gosub(app-allowlist-check,s,1)\" into $SMOKE_CTX replace" >/dev/null
		ast "dialplan add extension \"$e,4,Set(DB($SMOKE_FAMILY/\${EXTEN})=\${IF(\$[0\${CALLED_ALLOWLIST}=1]?allowed:blocked)})\" into $SMOKE_CTX replace" >/dev/null
		ast "dialplan add extension \"$e,5,Hangup()\" into $SMOKE_CTX replace" >/dev/null
	done
	ast "dialplan show $SMOKE_CTX" | grep -q "Gosub(app-allowlist-check" || { fail "could not add temporary dialplan"; return; }

	probe() { # probe CALLERID -> allowed|blocked|""
		ast "database del $SMOKE_FAMILY $1" >/dev/null
		ast "channel originate Local/$1@$SMOKE_CTX application Wait 1" >/dev/null
		local _try; for _try in 1 2 3 4 5 6; do sleep 0.5; local v; v=$(astdb_get "$SMOKE_FAMILY" "$1"); [[ -n $v ]] && { echo "$v"; return; }; done
	}
	if [[ -z $(astdb_get allowlist "$NUM_A") ]]; then
		ast "database put allowlist $NUM_A smoke-A" >/dev/null; CLEANUP_NUMBERS+=("$NUM_A")
	fi
	[[ $(probe "$NUM_A") == allowed ]] && pass "listed caller $NUM_A allowed" || fail "listed caller $NUM_A not allowed"
	local kc; kc=$(astdb_get allowlist knowncallers)
	local r; r=$(probe "$NUM_ABSENT")
	if [[ $r == blocked ]]; then pass "unlisted caller $NUM_ABSENT blocked"
	elif [[ -n $kc && $r == allowed ]]; then info "unlisted caller allowed (knowncallers on; is $NUM_ABSENT in Contact Manager?)"
	else fail "unlisted caller $NUM_ABSENT result '${r:-none}'"; fi
	ast "database del allowlist $NUM_A" >/dev/null
	[[ $(probe "$NUM_A") == blocked ]] && pass "removed caller $NUM_A blocked" || fail "removed caller $NUM_A still allowed"
	# Finding 1: caller ID equal to a settings key must not pass.
	if [[ -n $(astdb_get allowlist dest) ]]; then
		[[ $(probe dest) == blocked ]] && pass "caller ID 'dest' blocked (finding 1)" || xfail "caller ID 'dest' allowed (finding 1)"
	else skip "finding 1 probe (no blocked-call destination set)"; fi
	if [[ -n $kc ]]; then
		[[ $(probe knowncallers) == blocked ]] && pass "caller ID 'knowncallers' blocked (finding 1)" || xfail "caller ID 'knowncallers' allowed (finding 1)"
	fi
}

st_i18n() {
	stage "i18n (local)"
	command -v msgfmt >/dev/null || { skip "i18n (gettext not installed)"; return; }
	local d=$MODULE_SRC/i18n pot=$MODULE_SRC/i18n/allowlist.pot po out
	[[ -f $pot ]] && pass "allowlist.pot present" || { fail "allowlist.pot missing"; return; }
	msgfmt --check -o /dev/null "$pot" 2>/dev/null && pass "allowlist.pot parses" || fail "allowlist.pot has syntax errors"
	# Is the template current with the source? (FreePBX marks strings with _() in PHP and JS)
	( cd "$MODULE_SRC" && git ls-files '*.php' '*.agi' 2>/dev/null || find . -name '*.php' -o -name '*.agi' ) | grep -v '^tests/' > "$TMPD/php.list"
	xgettext --from-code=UTF-8 -L PHP -k_ -o "$TMPD/php.pot" -D "$MODULE_SRC" -f "$TMPD/php.list" 2>/dev/null
	xgettext --from-code=UTF-8 -L JavaScript -k_ -o "$TMPD/js.pot" -D "$MODULE_SRC" assets/js/allowlist.js 2>/dev/null
	msgcat -o "$TMPD/src.pot" "$TMPD"/php.pot "$TMPD"/js.pot 2>/dev/null
	ids() { msgattrib --no-obsolete "$1" 2>/dev/null | msgcat --no-location --no-wrap --sort-output - 2>/dev/null | sed -n 's/^msgid "\(.\+\)"$/\1/p' | sort -u; }
	ids "$TMPD/src.pot" > "$TMPD/src.ids"; ids "$pot" > "$TMPD/pot.ids"
	[[ -s $TMPD/src.ids ]] || { fail "could not extract source strings with xgettext"; return; }
	local missing stale
	missing=$(comm -23 "$TMPD/src.ids" "$TMPD/pot.ids" | wc -l); stale=$(comm -13 "$TMPD/src.ids" "$TMPD/pot.ids" | wc -l)
	[[ $missing -eq 0 ]] && pass "pot has every source string" || fail "pot is missing $missing source strings (regenerate it)"
	info "pot has $stale strings no longer in the source"
	for po in "$d"/*/LC_MESSAGES/allowlist.po; do
		[[ -f $po ]] || continue
		local lang; lang=$(basename "$(dirname "$(dirname "$po")")")
		msgfmt --check -o "$TMPD/$lang.mo" "$po" 2>"$TMPD/$lang.err" && pass "$lang .po compiles" || { fail "$lang .po: $(head -1 "$TMPD/$lang.err")"; continue; }
		info "$lang: $(msgfmt --statistics -o /dev/null "$po" 2>&1)"
		out=$(msgcmp --use-untranslated "$po" "$pot" 2>&1 | grep -c 'this message is used but not defined') || true
		[[ $out -eq 0 ]] && pass "$lang .po covers every pot string" || fail "$lang .po lacks $out pot strings (msgmerge it)"
		if [[ -f ${po%.po}.mo ]]; then
			cmp -s <(msgunfmt "${po%.po}.mo" 2>/dev/null) <(msgunfmt "$TMPD/$lang.mo" 2>/dev/null) \
				&& pass "$lang .mo matches .po" || fail "$lang .mo is stale (recompile with msgfmt)"
		else fail "$lang .mo missing"; fi
	done
}

# ---------- main ----------
STAGES=("$@"); [[ ${#STAGES[@]} -eq 0 ]] && STAGES=(preflight install dialplan cli gql behavior i18n)
printf 'allowlist smoke test  host=%s  run=%s  %s\n' "${HOST:-<none>}" "$RUN_ID" "$(date '+%Y-%m-%d %H:%M %Z')"
for s in "${STAGES[@]}"; do
	case $s in preflight|install|dialplan|cli|gql|behavior|i18n) "st_$s" ;; *) die 2 "unknown stage: $s" ;; esac
done
printf '\nRESULT  pass=%d fail=%d xfail=%d skip=%d\n' "$PASS" "$FAIL" "$XFAIL" "$SKIP"
[[ $FAIL -gt 0 ]] && exit 1
[[ $STRICT == 1 && $XFAIL -gt 0 ]] && exit 1
exit 0
