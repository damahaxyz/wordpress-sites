#!/usr/bin/env bash
set -Eeuo pipefail
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test_dir="$(mktemp -d)"
export TROVESIA_DEPLOY_TEST_LOG="${test_dir}/commands.log"
export PATH="${project_dir}/tests/deploy-stubs:${PATH}"
export DEPLOY_HOST='test-only'
export TROVESIA_DEPLOY_TEST_FAIL=1
if bash "${project_dir}/scripts/deploy-component.sh" theme >/dev/null 2>&1; then
    echo 'FAIL: failed precheck did not stop deployment' >&2; exit 1
fi
if rg -q '^rsync|docker compose restart' "${TROVESIA_DEPLOY_TEST_LOG}"; then
    echo 'FAIL: deployment changed state after failed precheck' >&2; exit 1
fi
export TROVESIA_DEPLOY_TEST_FAIL=0
bash "${project_dir}/scripts/deploy-component.sh" theme >/dev/null
[[ "$(rg -c 'security-baseline.sh check' "${TROVESIA_DEPLOY_TEST_LOG}")" == 3 ]]
rg -q 'security-baseline.sh apply' "${TROVESIA_DEPLOY_TEST_LOG}"
rg -q 'security-baseline.sh seal' "${TROVESIA_DEPLOY_TEST_LOG}"
echo 'Checking monitor-only deployment isolation...'
: > "${TROVESIA_DEPLOY_TEST_LOG}"
export TROVESIA_DEPLOY_TEST_FAIL=1
if bash "${project_dir}/scripts/deploy-security.sh" --monitor-only >/dev/null 2>&1; then
    echo 'FAIL: monitor deployment bypassed failed precheck' >&2; exit 1
fi
if rg -q '^rsync|systemctl restart' "${TROVESIA_DEPLOY_TEST_LOG}"; then
    echo 'FAIL: monitor deployment changed state after failed precheck' >&2; exit 1
fi
: > "${TROVESIA_DEPLOY_TEST_LOG}"
export TROVESIA_DEPLOY_TEST_FAIL=0
bash "${project_dir}/scripts/deploy-security.sh" --monitor-only >/dev/null
[[ "$(rg -c 'security-baseline.sh check' "${TROVESIA_DEPLOY_TEST_LOG}")" == 2 ]]
rg -q 'systemctl restart trovesia-security-watch.service' "${TROVESIA_DEPLOY_TEST_LOG}"
rg -q 'security-tools-' "${TROVESIA_DEPLOY_TEST_LOG}"
if rg -q 'force-recreate|restart wordpress|destroy_all|security-baseline.sh seal' "${TROVESIA_DEPLOY_TEST_LOG}"; then
    echo 'FAIL: monitor deployment touched web containers, admin sessions or code manifest' >&2; exit 1
fi
echo "PASS: failed precheck blocks all rsync/restarts; successful workflow includes pre/post checks and permission restoration. Test log: ${TROVESIA_DEPLOY_TEST_LOG}"
