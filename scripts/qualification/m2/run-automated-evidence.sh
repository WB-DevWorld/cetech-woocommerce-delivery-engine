#!/usr/bin/env bash
# M2 acceptance, part A: run the automated evidence at the packaged commit and write a
# receipt that names the commit, so the ledger can cite it.
#
# Usage: bash scripts/qualification/m2/run-automated-evidence.sh [receipt.json]
# Runs from the repository root. Needs vendor/bin/phpunit and npx (vitest).
#
# The whole PHPUnit and Vitest suites run, exactly as CI does, because several
# evidence classes depend on bootstrap state that earlier suites establish; running
# the named files alone can fail for that reason and would not be evidence. The
# case-to-file map below is checked for presence and written to the receipt so a
# reader can open the class that proves each ledger row.
#
# Writes no customer data: the receipt holds commit ids, file names and counts only.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
RECEIPT="${1:-$ROOT/m2-automated-evidence.json}"
cd "$ROOT"

HEAD_SHA="$(git rev-parse HEAD)"
TREE_SHA="$(git rev-parse 'HEAD^{tree}')"
DIRTY="$(git status --porcelain --untracked-files=no | wc -l | tr -d ' ')"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"

# Ledger case => evidence files (PHPUnit classes or Vitest files). Several cases share a file.
declare -A EVIDENCE=(
  [C1-durations]="tests/Unit/Selector/DeliveryEstimatePrecedenceTest.php"
  [C1-email-wording]="tests/Unit/Presentation/CustomerOrderMachineFactsAndAuthorityTest.php"
  [C2-pickup-source-independent]="tests/Unit/Runtime/SourceIndependentPickupTest.php"
  [C7-mixed-origin-totals]="tests/Unit/Shipping/MixedOriginGroupingTest.php"
  [C8-pdp-gate]="tests/Unit/Presentation/Frontend/ProductDeliveryGateRendererTest.php tests/js/product-delivery-selector.test.js"
  [C9-compact-line]="tests/Unit/Presentation/Frontend/ProductDeliveryGateRendererTest.php tests/Unit/Presentation/CustomerStorefrontCopyTest.php tests/js/blocks-checkout.test.js"
  [C10-pickup-endpoint-invalidation]="tests/Unit/Cart/PickupEndpointReselectionTest.php"
  [C11-mixed-cart-native-address]="tests/Unit/Checkout/MixedCartNativeAddressTest.php"
  [C12-coherent-reselection]="tests/Unit/Cart/PickupEndpointReselectionTest.php"
  [C14-order-surface-authority]="tests/Unit/Presentation/CustomerOrderMachineFactsAndAuthorityTest.php"
  [S2-save-and-apply]="tests/Unit/Presentation/Admin/M2AdminDefectsTest.php"
  [S3-S4-shipment-atomicity]="tests/Unit/Shipment/ShipmentAggregateRepairAndJoinedWritesTest.php"
  [S6-attention-truthful]="tests/Unit/Shipment/AttentionIndexTruthfulnessTest.php"
  [S7-version-identity]="tests/Unit/Persistence/SchemaV6InspectionTest.php tests/Unit/Bootstrap/WordPressCompatibilityFloorTest.php tests/Unit/Presentation/Admin/PluginIdentityBadgeTest.php"
  [S9-csv-round-trip]="tests/Unit/Bulk/CatalogCsvRoundTripTest.php"
  [S10-inherited-readiness]="tests/Unit/Configuration/ScopedConfigurationSemanticsTest.php tests/Unit/Presentation/Admin/PluginIdentityBadgeTest.php"
)

missing=()
for case in "${!EVIDENCE[@]}"; do
  for file in ${EVIDENCE[$case]}; do
    [[ -f "$file" ]] || missing+=("$case:$file")
  done
done

set +e
PHP_OUTPUT="$(vendor/bin/phpunit --no-progress 2>&1)"
PHP_EXIT=$?
set -e
PHP_SUMMARY="$(printf '%s\n' "$PHP_OUTPUT" | grep -E '^(OK|Tests:)' | tail -1)"
PHP_STATUS=$([[ $PHP_EXIT -eq 0 ]] && echo "pass" || echo "fail")
printf '%s\n' "$PHP_OUTPUT" | tail -3

set +e
JS_OUTPUT="$(npx vitest run 2>&1)"
JS_EXIT=$?
set -e
JS_SUMMARY="$(printf '%s\n' "$JS_OUTPUT" | sed 's/\x1b\[[0-9;]*m//g' | grep -E 'Tests ' | tail -1 | tr -s ' ' | sed 's/^ //')"
JS_STATUS=$([[ $JS_EXIT -eq 0 ]] && echo "pass" || echo "fail")
printf '%s\n' "$JS_OUTPUT" | sed 's/\x1b\[[0-9;]*m//g' | grep -E 'Test Files|Tests '

OVERALL="pass"
[[ "$PHP_STATUS" == "fail" || "$JS_STATUS" == "fail" ]] && OVERALL="fail"
((${#missing[@]})) && OVERALL="incomplete"

{
  echo '{'
  echo '  "format": "cetech-m2-automated-evidence-v1",'
  echo "  \"generated_at\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
  echo "  \"head\": \"$HEAD_SHA\","
  echo "  \"tree\": \"$TREE_SHA\","
  echo "  \"working_tree_dirty_files\": $DIRTY,"
  echo "  \"php\": \"$PHP_VERSION\","
  echo "  \"phpunit\": {\"status\": \"$PHP_STATUS\", \"summary\": \"$PHP_SUMMARY\"},"
  echo "  \"vitest\": {\"status\": \"$JS_STATUS\", \"summary\": \"$JS_SUMMARY\"},"
  echo "  \"overall\": \"$OVERALL\","
  echo '  "cases": {'
  first=1
  for case in $(printf '%s\n' "${!EVIDENCE[@]}" | sort); do
    [[ $first -eq 1 ]] || echo ','
    first=0
    files_json="$(printf '%s\n' ${EVIDENCE[$case]} | awk 'BEGIN{ORS=""} NR>1{print ", "} {print "\""$0"\""}')"
    printf '    "%s": [%s]' "$case" "$files_json"
  done
  echo
  echo '  },'
  missing_json="$(printf '%s\n' "${missing[@]:-}" | awk 'NF' | awk 'BEGIN{ORS=""} NR>1{print ", "} {print "\""$0"\""}')"
  echo "  \"missing_evidence\": [${missing_json}]"
  echo '}'
} > "$RECEIPT"

echo "receipt: $RECEIPT"
echo "head: $HEAD_SHA (dirty files: $DIRTY)"
echo "phpunit: $PHP_STATUS  vitest: $JS_STATUS  overall: $OVERALL"
if ((${#missing[@]})); then
  echo "missing evidence files (the packaged commit must contain them):"
  printf '  %s\n' "${missing[@]}"
fi
[[ "$OVERALL" == "pass" ]]
