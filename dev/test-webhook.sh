#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# dev/test-webhook.sh
#
# Simulates a Waffy webhook POST to the local ddev store.
#
# Usage:
#   ./dev/test-webhook.sh                         # fires PAID for a fresh test order
#   ./dev/test-webhook.sh CASHOUT_IN_PROGRESS     # test a different status
#   ./dev/test-webhook.sh COMPLETED
#   ./dev/test-webhook.sh CREATED
#
# What it does:
#   1. Picks the most recent Magento order (or creates a seed milestone ID on it)
#   2. Sets ext_order_id = <MILESTONE_ID> on that order so the webhook can find it
#   3. POSTs the Waffy webhook payload to https://magento-test.ddev.site/waffy/webhook
#   4. Prints the HTTP response
#
# For external access (let Waffy actually call you):
#   Run `ddev share` in a separate terminal — it prints a public ngrok URL.
#   Give that URL + /waffy/webhook to the Waffy team.
# ──────────────────────────────────────────────────────────────────────────────

set -euo pipefail

STORE_URL="https://magento-test.ddev.site"
WEBHOOK_URL="${STORE_URL}/waffy/webhook"
MILESTONE_ID="test-milestone-$(date +%s)"
STATUS="${1:-PAID}"
REFERENCE_ID="txn_local_test_123"

# ── Validate status ──────────────────────────────────────────────────────────
VALID_STATUSES=("CREATED" "PAID" "CASHOUT_IN_PROGRESS" "COMPLETED")
VALID=0
for s in "${VALID_STATUSES[@]}"; do [[ "$s" == "$STATUS" ]] && VALID=1; done
if [[ $VALID -eq 0 ]]; then
  echo "❌  Invalid status '$STATUS'. Use: CREATED | PAID | CASHOUT_IN_PROGRESS | COMPLETED"
  exit 1
fi

echo "────────────────────────────────────────────────────────"
echo "  Waffy Webhook Local Test"
echo "  Status:      $STATUS"
echo "  Milestone:   $MILESTONE_ID"
echo "  Endpoint:    $WEBHOOK_URL"
echo "────────────────────────────────────────────────────────"

# ── Step 1: find the most recent order in the local DB and stamp it ──────────
echo ""
echo "▶ Stamping a recent order with ext_order_id = $MILESTONE_ID ..."

# Adjust DB credentials to match your ddev config (defaults: db/db/db)
ORDER_INCREMENT=$(ddev exec mysql -u db -pdb db --skip-column-names -e \
  "SELECT increment_id FROM sales_order ORDER BY entity_id DESC LIMIT 1;" 2>/dev/null | tr -d '\r')

if [[ -z "$ORDER_INCREMENT" ]]; then
  echo "⚠  No orders found in the database."
  echo "   Place a test order first (any payment method), then re-run this script."
  echo ""
  echo "   Firing the webhook anyway — expect a 'order not found' warning in the logs."
else
  echo "   Using order #$ORDER_INCREMENT"
  ddev exec mysql -u db -pdb db -e \
    "UPDATE sales_order SET ext_order_id='${MILESTONE_ID}' WHERE increment_id='${ORDER_INCREMENT}';" 2>/dev/null
  echo "   ✓ ext_order_id set."
fi

# ── Step 2: fire the webhook ─────────────────────────────────────────────────
echo ""
echo "▶ POSTing webhook payload ..."
echo ""

PAYLOAD=$(cat <<EOF
{
  "contractId": "${MILESTONE_ID}",
  "status": "${STATUS}",
  "referenceId": "${REFERENCE_ID}"
}
EOF
)

echo "$PAYLOAD"
echo ""

RESPONSE=$(curl -s -w "\n\nHTTP %{http_code}" \
  -X POST "$WEBHOOK_URL" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "User-Agent: Java/11.0.31" \
  -d "$PAYLOAD")

echo "────────────────────────────────────────────────────────"
echo "Response:"
echo "$RESPONSE"
echo "────────────────────────────────────────────────────────"

# ── Step 3: tail the Magento log ─────────────────────────────────────────────
echo ""
echo "▶ Last 20 lines of var/log/system.log (Waffy entries):"
echo ""
ddev exec grep -i "waffy" /var/www/html/var/log/system.log 2>/dev/null | tail -20 || echo "   (no waffy entries yet — check var/log/exception.log if the request failed)"

echo ""
echo "────────────────────────────────────────────────────────"
echo "  Done."
echo ""
echo "  💡 To let Waffy actually call your local store:"
echo "     Run in a separate terminal:  ddev share"
echo "     Then give the printed ngrok URL + /waffy/webhook to the Waffy team."
echo "────────────────────────────────────────────────────────"
