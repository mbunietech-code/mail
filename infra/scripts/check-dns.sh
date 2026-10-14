#!/usr/bin/env bash
# Release check SEC-T49 (risk R-25): email authentication for the company domain.
#   infra/scripts/check-dns.sh mt.co.tz [dkim-selector ...]
# Exit status 0 only when SPF, DKIM and an enforcing DMARC policy are all present.
set -uo pipefail

domain="${1:?domain}"; shift || true
selectors=("$@")
[ ${#selectors[@]} -eq 0 ] && selectors=(hostingermail1 hostingermail2 hostingermail3 hostingermail default selector1 selector2 google)
[[ "$domain" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "invalid domain"; exit 2; }

txt() { dig +short TXT "$1" 2>/dev/null | tr -d '"' | tr '\n' ' '; }
fail=0

mx=$(dig +short MX "$domain" | sort -n | awk '{print $2}' | tr '\n' ' ')
echo "MX     : ${mx:-none}"

spf=$(txt "$domain" | grep -o 'v=spf1[^ ]*\( [^v][^ ]*\)*' | head -1)
if [ -z "$spf" ]; then echo "SPF    : MISSING"; fail=1
elif [[ "$spf" == *"-all"* ]]; then echo "SPF    : ok (hard fail) — $spf"
elif [[ "$spf" == *"~all"* ]]; then echo "SPF    : weak (~all softfail) — $spf"
else echo "SPF    : WEAK (no -all/~all) — $spf"; fail=1; fi

dkim_found=""
for s in "${selectors[@]}"; do
  rec=$(txt "${s}._domainkey.${domain}")
  cname=$(dig +short CNAME "${s}._domainkey.${domain}")
  if [[ "$rec" == *"p="* || -n "$cname" ]]; then dkim_found="$dkim_found $s"; fi
done
if [ -n "$dkim_found" ]; then echo "DKIM   : ok (selectors:${dkim_found})"; else echo "DKIM   : NOT FOUND for selectors ${selectors[*]} — enable DKIM in hPanel or pass the right selector"; fail=1; fi

dmarc=$(txt "_dmarc.${domain}")
policy=$(grep -o 'p=[a-z]*' <<< "$dmarc" | head -1 | cut -d= -f2)
case "$policy" in
  reject)     echo "DMARC  : ok (p=reject) — $dmarc" ;;
  quarantine) echo "DMARC  : acceptable (p=quarantine) — $dmarc" ;;
  none)       echo "DMARC  : NOT ENFORCED (p=none) — spoofed mail is delivered. Release blocker R-25"; fail=1 ;;
  *)          echo "DMARC  : MISSING"; fail=1 ;;
esac
[[ "$dmarc" == *"rua="* ]] || echo "         note: no rua= reporting address; aggregate reports help find legitimate senders before enforcing"

[ $fail -eq 0 ] && echo "RESULT : PASS" || echo "RESULT : FAIL (see docs/SECURITY_HARDENING_CHECKLIST.md §DNS)"
exit $fail
